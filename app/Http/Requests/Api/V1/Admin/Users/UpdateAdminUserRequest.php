<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Enums\UserRole;
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
        'email',
        'phone',
        'phone_country_code',
        'locale',
        'is_active',
        'role',
        'company_name',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $targetId = (int) $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
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
            'role' => [
                'sometimes',
                'string',
                'in:'.implode(
                    ',',
                    array_merge(UserRole::adminValues(), [UserRole::Customer->value]),
                ),
            ],
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
        });
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
