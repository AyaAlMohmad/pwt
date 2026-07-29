<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Sms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for PUT /api/v1/admin/sms-settings.
 */
final class UpdateSmsSettingsRequest extends FormRequest
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
            'driver' => ['sometimes', 'string', 'in:stub,twilio,unifonic,aws_sns,firebase_sms'],
            'sender' => ['sometimes', 'nullable', 'string', 'max:255'],
            'driver_config' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
