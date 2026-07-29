<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Request body for POST /api/v1/auth/register.
 *
 * Phone is NEVER accepted from the client — it is sourced from the
 * registration token's tokenable user (server-side trust boundary).
 */
final class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:160',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->letters()->numbers(),
            ],
            'locale' => ['nullable', 'string', 'in:en,ar'],
            'account_type' => ['required', 'string', 'in:individual,company'],
            'company_name' => [
                'nullable',
                'string',
                'max:120',
                'required_if:account_type,company',
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
        ];
    }
}
