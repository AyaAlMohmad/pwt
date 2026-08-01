<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\User;

use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request body for PATCH /api/v1/me.
 *
 * Partial profile update — every field is `sometimes`. Sensitive fields
 * (`email`, `phone`, `password`) require the current password to be re-supplied.
 */
final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $userId = $user !== null ? $user->id : 0;

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'first_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:60'],
            'last_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:60'],
            'email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:160',
                Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at'),
            ],
            'phone_e164' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                new PhoneNumber,
                function ($attribute, $value, $fail) use ($userId): void {
                    $cc = $this->input('phone_country_code');
                    $phone = $this->input('phone');
                    if ($cc === null || $phone === null) {
                        return;
                    }
                    $exists = User::query()
                        ->where('phone_country_code', $cc)
                        ->where('phone', $phone)
                        ->where('id', '!=', $userId)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($exists) {
                        $fail(__('auth.phone_taken'));
                    }
                },
            ],
            'phone_country_code' => ['nullable', 'string', 'max:8', 'regex:/^\+\d{1,4}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['sometimes', 'nullable', 'string', 'min:8'],
            'current_password' => [
                'required_with:email,phone_e164,password',
                'nullable',
                'string',
                'current_password:sanctum',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('auth.email_taken'),
            'current_password.current_password' => __('auth.password'),
            'current_password.required_with' => __('auth.password_required'),
        ];
    }
}
