<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Rules\PhoneNumber;
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/auth/forgot-password.
 *
 * Exactly one of `email` or `phone` is required. Response is always
 * generic 200 — no enumeration leak (architecture § 5.8 / 9.4).
 */
final class ForgotPasswordRequest extends FormRequest
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
            'email' => ['required_without:phone_e164', 'nullable', 'email:rfc', 'max:160'],
            'phone_e164' => ['required_without:email', 'nullable', 'string', 'max:20', new PhoneNumber],
            'phone_country_code' => ['nullable', 'string', 'max:8', 'regex:/^\+\d{1,4}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
