<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Region;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that the supplied `region_id` belongs to the supplied
 * `country_id`.
 *
 * The country id is captured at construction time so the rule object
 * is self-contained: the caller (a Form Request) computes the effective
 * country id (request override, falling back to the existing model's
 * country on PATCH) and passes it in.
 *
 * On mismatch, emits a single translated message that maps to the
 * `ERR_REGION_COUNTRY_MISMATCH` envelope code via the failedValidation
 * override on the calling Form Request.
 */
final class RegionBelongsToCountry implements ValidationRule
{
    public function __construct(
        private readonly ?int $countryId,
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The `exists:regions,id` rule (applied alongside this one) is
        // responsible for the type/existence check; we only enforce the
        // cross-field relationship here.
        if ($value === null || $value === '') {
            return;
        }

        if ($this->countryId === null) {
            // No country to reconcile against — let the country rule
            // produce its own failure; we abstain.
            return;
        }

        $regionId = is_numeric($value) ? (int) $value : null;
        if ($regionId === null) {
            return;
        }

        $exists = Region::query()
            ->where('id', $regionId)
            ->where('country_id', $this->countryId)
            ->exists();

        if (! $exists) {
            $fail(__('addresses.region_country_mismatch'));
        }
    }
}
