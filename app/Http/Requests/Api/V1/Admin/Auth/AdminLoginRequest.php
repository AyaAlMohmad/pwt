<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/admin/auth/login.
 *
 * Per docs/architecture/admin-auth.md § 3.1, the per-field messages
 * collapse to the generic `auth.invalid_credentials` string so an
 * empty body or malformed email cannot be distinguished from a wrong
 * password by an external observer. Structural 422 vs credentials
 * 401 distinction is preserved by Laravel's normal validation
 * pipeline — this just hides WHICH field failed.
 */
final class AdminLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge([
                'email' => mb_strtolower(trim($email)),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('auth.invalid_credentials'),
            'email.email' => __('auth.invalid_credentials'),
            'email.max' => __('auth.invalid_credentials'),
            'password.required' => __('auth.invalid_credentials'),
            'password.min' => __('auth.invalid_credentials'),
        ];
    }
}
