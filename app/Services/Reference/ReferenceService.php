<?php

declare(strict_types=1);

namespace App\Services\Reference;

use App\Models\Country;
use App\Models\Region;
use App\Models\Setting;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only helper for the public reference endpoints.
 *
 * Keeps the lookup, allowlist and "missing country = 404" logic out
 * of the controller so it can be reused by future admin endpoints
 * and by tests that need to assert the underlying datasets.
 */
final class ReferenceService
{
    /**
     * The fixed allowlist of setting keys safe to surface on
     * `GET /api/v1/settings/public`. Any other key — even one
     * accidentally flagged `is_public = true` in the database —
     * is excluded.
     *
     * @var list<string>
     */
    private const PUBLIC_SETTING_KEYS = [
        'supported_locales',
        'default_locale',
        'phone_number',
        'whatsapp_number',
        'contact_email',
        'location',
        'social_links',
        'support_hours',
    ];

    /**
     * Return the active GCC countries, ordered by code.
     *
     * @return Collection<int, Country>
     */
    public function listActiveCountries(): Collection
    {
        return Country::query()
            ->active()
            ->orderBy('code')
            ->get();
    }

    /**
     * Return the active regions for a country. The `{code}` arg is
     * expected to already be lowercased by the Form Request.
     *
     * @return Collection<int, Region>
     *
     * @throws HttpResponseException when the country is missing or
     *                               inactive (rendered as 404 with the
     *                               PWT envelope).
     */
    public function regionsForCountry(string $code): Collection
    {
        $country = Country::query()
            ->active()
            ->where('code', $code)
            ->first();

        if ($country === null) {
            throw new HttpResponseException(
                ApiResponse::error(
                    __('reference.country_not_found'),
                    Response::HTTP_NOT_FOUND,
                    code: 'ERR_COUNTRY_NOT_FOUND',
                ),
            );
        }

        return Region::query()
            ->active()
            ->where('country_id', $country->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Build the public settings payload by merging the database
     * allowlist with derived per-country maps.
     *
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $rows = Setting::query()
            ->public()
            ->whereIn('key', self::PUBLIC_SETTING_KEYS)
            ->get();

        /** @var array<string, mixed> $payload */
        $payload = [];
        foreach ($rows as $row) {
            $payload[$row->key] = $row->value;
        }

        $countries = Country::query()
            ->active()
            ->orderBy('code')
            ->get(['code', 'currency', 'vat_rate']);

        $currenciesByCountry = [];
        $vatRatesByCountry = [];
        foreach ($countries as $country) {
            $currenciesByCountry[$country->code] = $country->currency;
            $vatRatesByCountry[$country->code] = (float) $country->vat_rate;
        }

        $payload['currencies_by_country'] = $currenciesByCountry;
        $payload['vat_rates_by_country'] = $vatRatesByCountry;

        return $payload;
    }
}
