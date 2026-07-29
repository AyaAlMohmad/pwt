<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for PUT /api/v1/admin/payment-settings.
 */
final class UpdatePaymentSettingsRequest extends FormRequest
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
            'driver' => ['sometimes', 'string', 'in:stub,stripe,payfort'],
            'stripe' => ['sometimes', 'array'],
            'stripe.publishable_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe.secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe.webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe.return_url' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'stripe.cancel_url' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
        ];
    }
}
