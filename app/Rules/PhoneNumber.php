<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Phone\PhoneNumberNormaliser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a phone-number value to E.164 (`/^\+?[1-9]\d{7,14}$/`).
 *
 * The accepting shape is intentionally permissive: callers may submit
 * either a full E.164 number (with `+` prefix) or a bare local number
 * paired with a separate `phone_country_code` field. Normalisation to
 * E.164 happens upstream in the form-request `prepareForValidation()`,
 * which delegates to {@see PhoneNumberNormaliser}.
 */
final class PhoneNumber implements ValidationRule
{
    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('auth.phone_invalid'));

            return;
        }

        if (preg_match('/^\+?[1-9]\d{7,14}$/', $value) !== 1) {
            $fail(__('auth.phone_invalid'));
        }
    }
}
