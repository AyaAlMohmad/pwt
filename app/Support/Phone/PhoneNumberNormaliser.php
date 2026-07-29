<?php

declare(strict_types=1);

namespace App\Support\Phone;

use App\Models\Country;
use Throwable;

/**
 * Centralises the phone-number normalisation logic shared by the auth
 * form-requests.
 *
 * The auth module accepts two input shapes:
 *
 *  1. Pre-normalised E.164:        { "phone": "+971501234567" }
 *  2. Local + separate dial code:  { "phone_country_code": "+971", "phone": "501234567" }
 *
 * In both cases, the user record persists the dial code in
 * `users.phone_country_code` and the rest of the digits in `users.phone`.
 * Look-ups always recombine to the E.164 form (`$cc . $phone`).
 */
final class PhoneNumberNormaliser
{
    /**
     * Fallback list of known E.164 dial codes used when no Country rows
     * are seeded (e.g. minimal-seed unit tests). Sorted longest-first by
     * the probe loop so 3-digit codes like `+971` match before `+97` or
     * `+9` (neither of which is allocated, but the principle generalises
     * to overlaps such as `+1` (US/CA) vs `+1242` (Bahamas)).
     *
     * Curated for the GCC + commonly-tested ranges. Production reads the
     * authoritative list from the `countries` table — see {@see probeDialCodes()}.
     *
     * @var list<string>
     */
    private const FALLBACK_DIAL_CODES = [
        // GCC (the project's primary market)
        '+971', '+966', '+974', '+965', '+973', '+968',
        // Common Middle East / Levant
        '+962', '+961', '+963', '+964', '+967', '+970', '+972',
        // Egypt / North Africa
        '+20', '+212', '+213', '+216', '+218', '+249',
        // Europe (sample — extend as needed)
        '+44', '+33', '+49', '+39', '+34', '+31', '+32', '+41', '+43',
        '+45', '+46', '+47', '+48', '+351', '+353', '+358',
        // Indian sub-continent
        '+91', '+92', '+94', '+880', '+977',
        // Asia-Pacific
        '+60', '+62', '+63', '+65', '+66', '+81', '+82', '+84', '+86', '+852', '+855', '+856', '+886',
        // Americas
        '+1', '+52', '+54', '+55', '+56', '+57', '+58', '+591', '+593', '+595', '+598',
        // Misc
        '+27', '+90', '+98',
    ];

    /**
     * Memoised dial-code list (DB + fallback, sorted longest-first).
     * Static lifetime is one PHP process — re-resolved on the next boot.
     *
     * @var list<string>|null
     */
    private static ?array $dialCodeCache = null;

    /**
     * Normalise a `(phone_country_code, phone)` pair into separate
     * components, prefixed by `+`.
     *
     * @param  string|null  $phone  The phone fragment as supplied by the client.
     * @param  string|null  $phoneCountryCode  The dial code as supplied by the client.
     * @return array{phone_country_code: string|null, phone: string|null, e164: string|null}
     */
    public static function split(?string $phone, ?string $phoneCountryCode): array
    {
        $phone = self::trimOrNull($phone);
        $phoneCountryCode = self::trimOrNull($phoneCountryCode);

        if ($phone === null) {
            return ['phone_country_code' => $phoneCountryCode, 'phone' => null, 'e164' => null];
        }

        // Pre-normalised E.164 path — split into (cc, local).
        if (str_starts_with($phone, '+') && $phoneCountryCode === null) {
            // Probe known dial codes (DB-backed in prod, fallback table
            // in test envs) in DESCENDING length order so longer prefixes
            // like `+971` win over shorter ones like `+97`. This replaces
            // the previously greedy `\d{1,4}` regex which produced a
            // user-facing `phone_country_code` of `+9715` for the very
            // common UAE format.
            $codeMatch = self::matchDialCode($phone);
            if ($codeMatch !== null) {
                return [
                    'phone_country_code' => $codeMatch,
                    'phone' => substr($phone, strlen($codeMatch)),
                    'e164' => $phone,
                ];
            }

            // Last-resort: assume the dial code is the first 3 digits
            // (covers every code under +999). Pure round-trip safety.
            if (preg_match('/^(\+\d{3})(\d{4,14})$/', $phone, $matches) === 1) {
                return [
                    'phone_country_code' => $matches[1],
                    'phone' => $matches[2],
                    'e164' => $phone,
                ];
            }

            return [
                'phone_country_code' => null,
                'phone' => $phone,
                'e164' => $phone,
            ];
        }

        // Local-number path: dial code must be supplied separately.
        if ($phoneCountryCode !== null) {
            $local = ltrim($phone, '+');
            $cc = $phoneCountryCode;
            if (! str_starts_with($cc, '+')) {
                $cc = '+'.$cc;
            }

            return [
                'phone_country_code' => $cc,
                'phone' => $local,
                'e164' => $cc.$local,
            ];
        }

        // Phone supplied without a leading `+` and no dial code — return
        // unchanged so the rule will reject it.
        return [
            'phone_country_code' => null,
            'phone' => $phone,
            'e164' => null,
        ];
    }

    /**
     * Combine a `(country_code, phone)` pair stored on a User into the
     * E.164 string used as the identifier across the auth module.
     */
    public static function compose(string $countryCode, string $phone): string
    {
        $cc = str_starts_with($countryCode, '+') ? $countryCode : '+'.$countryCode;
        $local = ltrim($phone, '+');

        return $cc.$local;
    }

    /**
     * Match the longest known dial code that prefixes the given E.164
     * string, or `null` if none matches.
     */
    private static function matchDialCode(string $e164): ?string
    {
        foreach (self::probeDialCodes() as $code) {
            if (str_starts_with($e164, $code)) {
                $remainder = substr($e164, strlen($code));
                // Require at least 4 remaining digits — short numbers
                // would not be valid local subscriber numbers.
                if (strlen($remainder) >= 4 && ctype_digit($remainder)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the full dial-code list: union of the seeded `countries`
     * table and the curated fallback set, deduplicated and sorted by
     * descending length so longest-prefix wins. Memoised per process.
     *
     * The DB read is wrapped in a try/catch so a missing/empty table
     * (e.g. during boot, or in tests that skip the country seeder)
     * degrades gracefully to the fallback list.
     *
     * @return list<string>
     */
    private static function probeDialCodes(): array
    {
        if (self::$dialCodeCache !== null) {
            return self::$dialCodeCache;
        }

        $codes = self::FALLBACK_DIAL_CODES;

        try {
            $fromDb = Country::query()
                ->whereNotNull('phone_code')
                ->pluck('phone_code')
                ->all();

            foreach ($fromDb as $code) {
                $code = is_string($code) ? trim($code) : '';
                if ($code === '') {
                    continue;
                }
                if (! str_starts_with($code, '+')) {
                    $code = '+'.$code;
                }
                $codes[] = $code;
            }
        } catch (Throwable) {
            // Country table not migrated / DB unreachable — fall back.
        }

        // Deduplicate + sort longest-first.
        $codes = array_values(array_unique($codes));
        usort($codes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return self::$dialCodeCache = $codes;
    }

    /**
     * Reset the memoised dial-code cache. Intended for tests that seed
     * the `countries` table mid-process and want subsequent calls to
     * pick up the fresh rows.
     */
    public static function flushDialCodeCache(): void
    {
        self::$dialCodeCache = null;
    }

    private static function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
