<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Rules\PhoneNumber;
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Request body for POST /api/v1/auth/reset-password.
 *
 * Two valid input shapes:
 *   - email + token  (email channel)
 *   - phone + otp    (phone channel)
 *
 * Mixed pairs (phone+token, email+otp) are rejected by the cross-field
 * validator in `withValidator()`.
 */
final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'required_with:token', 'required_without:phone_e164', 'email:rfc', 'max:160'],
            'token' => ['nullable', 'required_with:email', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'phone_e164' => ['nullable', 'required_with:otp', 'required_without:email', 'string', 'max:20', new PhoneNumber],
            'phone_country_code' => ['nullable', 'string', 'max:8', 'regex:/^\+\d{1,4}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'otp' => ['nullable', 'required_with:phone_e164', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->letters()->numbers(),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasEmailPair = $this->filled('email') && $this->filled('token');
            $hasPhonePair = $this->filled('phone_e164') && $this->filled('otp');

            if ($hasEmailPair && $hasPhonePair) {
                $v->errors()->add('token', __('auth.reset_token_invalid'));

                return;
            }

            if (! $hasEmailPair && ! $hasPhonePair) {
                $v->errors()->add('token', __('auth.reset_token_invalid'));
            }
        });
    }
}
