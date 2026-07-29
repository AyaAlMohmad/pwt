<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Addresses;

use App\Models\Address;
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
 * PATCH /api/v1/me/addresses/{address}
 *
 * Partial update: every field is `sometimes`. Omitted fields are
 * unchanged; explicit-null clears the nullable fields per the
 * documented PATCH semantics.
 *
 * The region/country consistency rule reconciles the MERGED state:
 *   - if country_id is in the payload → use it
 *   - else fall back to the existing model's country_id
 *
 * Authorization runs in the controller via $this->authorize('update',
 * $address) so the policy fires before validation begins (Laravel
 * resolves the route-model binding first).
 */
final class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Map consumer-facing `city_id` to internal `region_id`.
        if ($this->has('city_id')) {
            $this->merge(['region_id' => $this->input('city_id')]);
        }

        if ($this->has('recipient_phone') || $this->has('recipient_phone_country_code')) {
            $phone = $this->input('recipient_phone');
            $cc = $this->input('recipient_phone_country_code');
            $split = PhoneNumberNormaliser::split(
                is_string($phone) ? $phone : null,
                is_string($cc) ? $cc : null,
            );

            $merge = [];
            if ($phone !== null) {
                $merge['recipient_phone'] = $split['e164'] ?? $split['phone'];
            }
            if ($cc !== null) {
                $merge['recipient_phone_country_code'] = $split['phone_country_code'];
            }
            if ($merge !== []) {
                $this->merge($merge);
            }
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
            'label' => ['sometimes', 'required', 'string', 'max:60'],
            'country_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:countries,id',
                $this->activeCountryRule(),
            ],
            'region_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:regions,id',
                new RegionBelongsToCountry($countryId),
            ],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'recipient_phone' => ['sometimes', 'nullable', 'string', 'max:20', new PhoneNumber],
            'recipient_phone_country_code' => [
                'sometimes', 'nullable', 'string', 'max:6', 'regex:/^\+?\d{1,5}$/',
            ],
            'line1' => ['sometimes', 'required', 'string', 'max:200'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'area' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Cross-field pairing rules (applied to the merged state).
     */
    public function withValidator(Validator $validator): void
    {
        /** @var ConcreteValidator $validator */
        $validator->after(function (ConcreteValidator $v): void {
            $address = $this->resolveAddress();

            $hasLat = $this->has('latitude') ? $this->filled('latitude') : ($address?->latitude !== null);
            $hasLng = $this->has('longitude') ? $this->filled('longitude') : ($address?->longitude !== null);

            // Only fire the pairing rule when the payload touches lat OR
            // lng — purely-unrelated PATCHes (e.g. label-only) should
            // never trigger it.
            $payloadTouchesCoords = $this->has('latitude') || $this->has('longitude');
            if ($payloadTouchesCoords && $hasLat !== $hasLng) {
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
     * Country id to reconcile region_id against: explicit override if
     * present in the payload, otherwise the existing model's country.
     */
    protected function resolveCountryId(): ?int
    {
        if ($this->has('country_id')) {
            $value = $this->input('country_id');

            return is_numeric($value) ? (int) $value : null;
        }

        $address = $this->resolveAddress();

        return $address?->country_id;
    }

    /**
     * Route-model bound Address (or null in non-routed contexts).
     */
    protected function resolveAddress(): ?Address
    {
        $address = $this->route('address');

        return $address instanceof Address ? $address : null;
    }

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
