<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Rules\PhoneNumber;
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/auth/register-otp.
 *
 * Accepts either a full E.164 phone (with `+`) OR a bare local number
 * combined with a separate `phone_country_code`. After validation the
 * normalised E.164 value is available via `$request->input('phone_e164')`.
 */
final class RegisterOtpRequest extends FormRequest
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
            'phone_e164' => ['required', 'string', 'max:20', new PhoneNumber],
            'phone_country_code' => ['nullable', 'string', 'max:8', 'regex:/^\+\d{1,4}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'account_type' => ['required', 'string', 'in:individual,company'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_e164.required' => __('auth.phone_invalid'),
            'phone_country_code.regex' => __('auth.phone_invalid'),
        ];
    }
}
