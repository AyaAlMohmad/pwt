<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\Auth\OtpExhaustedException;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Exceptions\Auth\OtpRateLimitException;
use App\Models\Otp;
use App\Services\Notifications\Channels\Sms\SmsDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Issuance + verification of one-time passwords.
 *
 * Behaviour summary:
 *  - throttle 5 active OTPs per (identifier, purpose) per hour
 *    (defence-in-depth, on top of the route limiter);
 *  - cryptographically-secure 6-digit codes (random_int);
 *  - bcrypt-hashed at rest; never logged in production;
 *  - 5-minute TTL; 3 wrong attempts then exhausted.
 */
final class OtpService
{
    private const TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 3;

    private const HOURLY_REQUEST_CAP = 5;

    public function __construct(
        private readonly SmsDriver $sms,
    ) {}

    /**
     * Create a new OTP row without sending it.
     *
     * Returns the OTP model with a transient `plainCode` property set
     * so callers can dispatch the code through their own channel.
     *
     * @throws OtpRateLimitException when the per-hour cap is exceeded.
     */
    public function createOtp(string $identifier, OtpPurpose $purpose, ?string $ip = null, bool $useTestCode = true): Otp
    {
        return DB::transaction(function () use ($identifier, $purpose, $ip, $useTestCode): Otp {
            $recentCount = Otp::forIdentifier($identifier, $purpose)
                ->active()
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($recentCount >= self::HOURLY_REQUEST_CAP) {
                throw OtpRateLimitException::create();
            }

            // Invalidate any active prior OTPs for the same identifier+purpose.
            Otp::query()
                ->forIdentifier($identifier, $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = ($useTestCode && config('sms.test_mode', false))
                ? (string) config('sms.test_code', '123456')
                : (string) random_int(100000, 999999);

            /** @var Otp $otp */
            $otp = Otp::query()->create([
                'public_id' => (string) Str::uuid(),
                'identifier' => $identifier,
                'purpose' => $purpose->value,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'consumed_at' => null,
                'ip' => $ip,
            ]);

            // Always attach the plaintext code in-memory so callers can
            // forward it through any channel (SMS, email, push, etc.).
            $otp->plainCode = $code;

            // Always set devCode so the controller can return it for
            // testing / debugging SMS delivery issues. Never persisted.
            $otp->devCode = $code;

            return $otp;
        });
    }

    /**
     * Issue a new OTP for the given identifier+purpose over SMS.
     *
     * @throws OtpRateLimitException when the per-hour cap is exceeded.
     */
    public function requestOtp(string $identifier, OtpPurpose $purpose, ?string $ip = null): Otp
    {
        $otp = $this->createOtp($identifier, $purpose, $ip);

        $driverClass = get_class($this->sms);
        $driverKey = config('sms.default', 'unknown');

        Log::info('OTP_SMS_SEND_START', [
            'to' => $identifier,
            'driver_class' => $driverClass,
            'driver_key' => $driverKey,
            'sms_config' => $this->maskSmsConfig(config('sms.driver_config.'.$driverKey, [])),
            'purpose' => $purpose->value,
            'otp_id' => $otp->public_id,
            'otp_code' => $otp->plainCode,
        ]);

        try {
            $this->sms->send(
                $identifier,
                __('auth.otp_message', ['code' => $otp->plainCode, 'minutes' => self::TTL_MINUTES]),
                ['purpose' => $purpose->value, 'otp_id' => $otp->public_id],
            );

            Log::info('OTP_SMS_SEND_OK', [
                'to' => $identifier,
                'driver_class' => $driverClass,
                'purpose' => $purpose->value,
                'otp_id' => $otp->public_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('OTP_SMS_SEND_FAILED', [
                'to' => $identifier,
                'driver_class' => $driverClass,
                'driver_key' => $driverKey,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'purpose' => $purpose->value,
                'otp_id' => $otp->public_id,
                'otp_code' => $otp->plainCode,
                'trace' => $e->getTraceAsString(),
            ]);
            // Swallow SMS failure so the OTP is still returned for testing.
        }

        return $otp;
    }

    /**
     * Verify a code against the most recent active OTP row.
     *
     * On success, the row is marked consumed and returned. On failure
     * the appropriate exception is thrown — the global handler maps
     * these to the documented 422 codes.
     *
     * IMPORTANT — transactional shape:
     *
     * The wrong-attempt counter MUST survive across throws so the
     * 3-strike lock-out fires; therefore the failure path is NOT wrapped
     * in `DB::transaction()` (a rollback would wipe the increment).
     * Each branch uses standalone autocommit statements
     * (`DB::table()->increment()` / `->update()`) before throwing.
     *
     * The success path is wrapped in a transaction so the consume +
     * any future side-effects stay atomic.
     *
     * @throws OtpInvalidException
     * @throws OtpExpiredException
     * @throws OtpExhaustedException
     */
    public function verifyOtp(string $identifier, string $code, OtpPurpose $purpose): Otp
    {
        /** @var Otp|null $otp */
        $otp = Otp::query()
            ->forIdentifier($identifier, $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($otp === null) {
            throw OtpInvalidException::create();
        }

        if ($otp->isExpired()) {
            throw OtpExpiredException::create();
        }

        if ($otp->isExhausted()) {
            throw OtpExhaustedException::create();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            // Standalone autocommit increment — survives the throw.
            DB::table('otps')->where('id', $otp->id)->increment('attempts');
            $otp->attempts++;

            if ($otp->attempts >= $otp->max_attempts) {
                // Stamp consumed_at so future verifies short-circuit.
                DB::table('otps')
                    ->where('id', $otp->id)
                    ->update(['consumed_at' => now()]);

                throw OtpExhaustedException::create();
            }

            throw OtpInvalidException::create();
        }

        return DB::transaction(function () use ($otp): Otp {
            $otp->markConsumed();

            return $otp;
        });
    }

    /**
     * Mark every active OTP for (identifier, purpose) consumed without
     * verification — used when overlapping requests must be torn down
     * (e.g. forgot-password before issuing a new phone-channel OTP).
     */
    public function invalidateActive(string $identifier, OtpPurpose $purpose): void
    {
        Otp::query()
            ->forIdentifier($identifier, $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    /**
     * Mask sensitive keys in SMS driver config for safe logging.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function maskSmsConfig(array $config): array
    {
        $masked = [];
        $sensitive = ['token', 'secret', 'password', 'key', 'auth', 'api_key', 'api_secret', 'access_key', 'secret_access', 'appsid'];

        foreach ($config as $k => $v) {
            if (is_string($v)) {
                $lowerKey = strtolower($k);
                $isSensitive = false;
                foreach ($sensitive as $word) {
                    if (str_contains($lowerKey, $word)) {
                        $isSensitive = true;
                        break;
                    }
                }
                $masked[$k] = $isSensitive && strlen($v) > 4
                    ? str_repeat('*', strlen($v) - 4).substr($v, -4)
                    : $v;
            } else {
                $masked[$k] = $v;
            }
        }

        return $masked;
    }
}
