<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Mail;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for PUT /api/v1/admin/mail-settings.
 */
final class UpdateMailSettingsRequest extends FormRequest
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
            'driver' => ['sometimes', 'string', 'in:smtp,log,sendmail,ses,postmark'],
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['sometimes', 'nullable', 'string', 'in:tls,ssl,null'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
