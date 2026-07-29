<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Addresses;

use App\Models\Country;
use App\Rules\PhoneNumber;
use App\Rules\RegionBelongsToCountry;
use App\Support\Http\ApiResponse;
use App\Support\Phone\PhoneNumberNormaliser;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator as ConcreteValidator;

/**
 * POST /api/v1/me/addresses
 *
 * Server-recomputed fields (never trusted from client even if sent):
 *   - user_id              (set from $request->user()->id)
 *   - recipient_phone      (re-normalised through PhoneNumberNormaliser)
 *   - recipient_phone_country_code (re-prefixed with '+')
 *   - is_default           (coerced to bool; defaults to false)
 *   - timestamps           (Laravel-managed)
 *
 * Cross-field rules implemented via {@see withValidator()}:
 *   - latitude/longitude must pair (both present or both absent)
 *   - region_id (if present) must belong to country_id (delegated to
 *     {@see RegionBelongsToCountry} attached on the rules() side too)
 *
 * The address-cap check (10 per user, see config/addresses.php) is NOT
 * here: it runs inside the AddressService transaction so the check and
 * the insert share the same `lockForUpdate()`.
 */
final class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Map consumer-facing `city_id` to internal `region_id` so the
        // rest of the validation + service layer stays unchanged.
        if ($this->has('city_id')) {
            $this->merge(['region_id' => $this->input('city_id')]);
        }

        // Re-normalise the phone pair so DB writes always go in as
        // (+971, 501234567) regardless of whether the client sent the
        // local-plus-cc shape or a pre-formed E.164.
        if ($this->has('recipient_phone') || $this->has('recipient_phone_country_code')) {
            $phone = $this->input('recipient_phone');
            $cc = $this->input('recipient_phone_country_code');
            $split = PhoneNumberNormaliser::split(
                is_string($phone) ? $phone : null,
                is_string($cc) ? $cc : null,
            );

            $this->merge([
                'recipient_phone' => $split['e164'] ?? $split['phone'],
                'recipient_phone_country_code' => $split['phone_country_code'],
            ]);
        }

        if ($this->has('is_default')) {
            $this->merge([
                'is_default' => filter_var(
                    $this->input('is_default'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                ) ?? false,
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $countryId = $this->resolveCountryId();

        return [
            'label' => ['required', 'string', 'max:60'],
            'country_id' => [
                'nullable',
                'integer',
                'exists:countries,id',
                $this->activeCountryRule(),
            ],
            'region_id' => [
                'nullable',
                'integer',
                'exists:regions,id',
                new RegionBelongsToCountry($countryId),
            ],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'recipient_phone' => ['nullable', 'string', 'max:20', new PhoneNumber],
            'recipient_phone_country_code' => [
                'nullable', 'string', 'max:6', 'regex:/^\+?\d{1,5}$/',
            ],
            'line1' => ['required', 'string', 'max:200'],
            'line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Latitude/longitude pairing — single-coordinate submissions are
     * rejected with a field-specific message.
     */
    public function withValidator(Validator $validator): void
    {
        /** @var ConcreteValidator $validator */
        $validator->after(function (ConcreteValidator $v): void {
            $hasLat = $this->filled('latitude');
            $hasLng = $this->filled('longitude');
            if ($hasLat !== $hasLng) {
                $v->errors()->add(
                    $hasLat ? 'longitude' : 'latitude',
                    __('addresses.coordinates_must_pair'),
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country_id.exists' => __('addresses.country_invalid'),
            'recipient_phone.max' => __('auth.phone_invalid'),
            'recipient_phone_country_code.regex' => __('auth.phone_invalid'),
        ];
    }

    /**
     * Map the region-country cross-field failure onto the dedicated
     * envelope code `ERR_REGION_COUNTRY_MISMATCH`. All other failures
     * fall back to the generic `ERR_VALIDATION` handled by the global
     * exception handler.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $messages = $errors->toArray();

        $regionFailures = $errors->get('region_id');
        if (in_array(__('addresses.region_country_mismatch'), $regionFailures, true)) {
            throw new HttpResponseException(
                ApiResponse::error(
                    __('addresses.region_country_mismatch'),
                    422,
                    $messages,
                    'ERR_REGION_COUNTRY_MISMATCH',
                ),
            );
        }

        throw new HttpResponseException(
            ApiResponse::error(
                __('messages.validation_failed'),
                422,
                $messages,
                'ERR_VALIDATION',
            ),
        );
    }

    /**
     * Returns the country id this request targets (used by the
     * region-country cross-field rule).
     */
    protected function resolveCountryId(): ?int
    {
        $value = $this->input('country_id');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Closure rule ensuring the selected country is active. Kept as a
     * closure rather than baked into the `exists:` rule for a cleaner
     * error message (the column-condition syntax `exists:countries,id,is_active,1`
     * collapses two distinct failure modes into one message).
     */
    private function activeCountryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_numeric($value)) {
                return;
            }

            $isActive = Country::query()
                ->where('id', (int) $value)
                ->where('is_active', true)
                ->exists();

            if (! $isActive) {
                $fail(__('addresses.country_invalid'));
            }
        };
    }
}
