<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for DELETE /api/v1/me.
 *
 * Two safety guards:
 *   - `password`: must match the bcrypt hash on the authenticated user
 *     row (Laravel's `current_password:sanctum` rule).
 *   - `confirm`: must equal the literal string "DELETE" — defends
 *     against accidental DELETE requests by buggy clients.
 */
final class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password:sanctum'],
            'confirm' => ['required', 'in:DELETE'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.current_password' => __('auth.password'),
            'confirm.in' => __('messages.validation_failed'),
        ];
    }
}
