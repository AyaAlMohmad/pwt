<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/auth/verify-reset-otp.
 */
final class VerifyResetOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:160'],
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }
}
