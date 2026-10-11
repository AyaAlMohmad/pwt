<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\Address;
use App\Rules\PhoneNumber;
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Request body for PATCH /api/v1/admin/users/{user}.
 *
 * All fields are optional (`sometimes`) but at least one mutable field
 * MUST be present — enforced via the `required_without_all` cross-rule.
 *
 * Policy-level authorisation (`updateAdmin` + optional `changeRole`) is
 * asserted by the controller. The form request validates shape only.
 *
 * See docs/architecture/admin-users.md § 3.2.
 */
final class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone') || $this->has('phone_country_code')) {
            $split = PhoneNumberNormaliser::split(
                $this->input('phone'),
                $this->input('phone_country_code'),
            );
            $this->merge([
                'phone' => $split['phone'],
                'phone_country_code' => $split['phone_country_code'],
                'phone_e164' => $split['e164'],
            ]);
        }

        // Map the consumer-facing `address.city_id` onto the internal
        // `address.region_id` — same alias the customer address endpoints
        // accept (see StoreAddressRequest::prepareForValidation()).
        if ($this->has('address.city_id')) {
            /** @var array<string, mixed> $address */
            $address = (array) $this->input('address', []);
            $address['region_id'] = $address['city_id'];
            unset($address['city_id']);
            $this->merge(['address' => $address]);
        }

        // Accept a boolean shorthand for the verification flag so admin
        // UIs can send a toggle instead of a timestamp.
        $verified = $this->input('email_verified_at');
        if (is_bool($verified) || in_array($verified, ['true', 'false'], true)) {
            $this->merge([
                'email_verified_at' => filter_var($verified, FILTER_VALIDATE_BOOLEAN)
                    ? now()->toIso8601String()
                    : null,
            ]);
        }
    }

    /**
     * Mutable top-level fields. At least one MUST be present in the
     * request body — enforced by {@see withValidator()} because the
     * `sometimes` rule alone skips validation entirely when the field
     * is absent, which would otherwise let an empty body silently pass.
     *
     * @var list<string>
     */
    private const MUTABLE_FIELDS = [
        'name',
        'first_name',
        'last_name',
        'account_type',
        'email',
        'phone',
        'phone_country_code',
        'locale',
        'is_active',
        'role',
        'company_name',
        'contact_name',
        'contact_email',
        'password',
        'avatar_file_id',
        'email_verified_at',
        'address',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $targetId = (int) $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'first_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:60'],
            'last_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:60'],
            'account_type' => ['sometimes', 'string', 'in:'.implode(',', AccountType::values())],
            'email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:200',
                Rule::unique('users', 'email')->ignore($targetId)->whereNull('deleted_at'),
            ],
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                'required_with:phone_country_code',
            ],
            'phone_country_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:8',
                'regex:/^\+\d{1,4}$/',
                'required_with:phone',
            ],
            'phone_e164' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                new PhoneNumber,
            ],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'is_active' => ['sometimes', 'boolean'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:200'],
            'password' => ['sometimes', 'string', 'min:8'],
            'avatar_file_id' => ['sometimes', 'nullable', 'integer', 'exists:files,id'],
            // Null clears verification; a date (or `now`, normalised in
            // prepareForValidation) marks the address verified manually.
            'email_verified_at' => ['sometimes', 'nullable', 'date'],
            'role' => [
                'sometimes',
                'string',
                'in:'.implode(
                    ',',
                    array_merge(UserRole::adminValues(), [UserRole::Customer->value]),
                ),
            ],

            // Address book entry to edit. `address.id` targets a specific
            // row (ownership enforced by the `exists` scope below); when
            // omitted the service edits the target's default address, or
            // creates one when the address book is empty.
            'address' => ['sometimes', 'array'],
            'address.id' => [
                'sometimes',
                'integer',
                Rule::exists('addresses', 'id')
                    ->where('user_id', $targetId)
                    ->whereNull('deleted_at'),
            ],
            'address.label' => ['sometimes', 'string', 'max:60'],
            'address.country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'address.region_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
            'address.recipient_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address.recipient_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.recipient_phone_country_code' => ['sometimes', 'nullable', 'string', 'max:6', 'regex:/^\+?\d{1,5}$/'],
            'address.line1' => ['sometimes', 'string', 'max:200'],
            'address.line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.area' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'address.is_default' => ['sometimes', 'boolean'],
            'address.latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasMutable = false;
            foreach (self::MUTABLE_FIELDS as $field) {
                if ($this->has($field)) {
                    $hasMutable = true;
                    break;
                }
            }
            if (! $hasMutable) {
                $v->errors()->add(
                    'body',
                    __('admin.users.empty_update_body'),
                );
            }

            $this->validateAddressCreation($v);
        });
    }

    /**
     * `label` and `line1` are NOT NULL on `addresses`. They are optional
     * when the payload edits an existing row (partial update), but must
     * be present when the service has to insert a brand-new one — i.e.
     * no `address.id` was sent AND the target has an empty address book.
     */
    private function validateAddressCreation(Validator $v): void
    {
        if (! $this->has('address') || $this->has('address.id')) {
            return;
        }

        $targetId = (int) $this->route('user');
        $hasExisting = Address::query()->where('user_id', $targetId)->exists();
        if ($hasExisting) {
            return;
        }

        foreach (['label', 'line1'] as $field) {
            if ($this->input("address.$field") === null) {
                $v->errors()->add(
                    "address.$field",
                    __('validation.required', ['attribute' => "address.$field"]),
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('auth.email_taken'),
            'phone.required_with' => __('admin.users.phone_pair_required'),
            'phone_country_code.required_with' => __('admin.users.phone_pair_required'),
        ];
    }
}
