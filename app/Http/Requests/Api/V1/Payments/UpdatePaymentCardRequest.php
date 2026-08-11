<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for PATCH /api/v1/me/payment-cards/{id}.
 */
final class UpdatePaymentCardRequest extends FormRequest
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
            'cardholder_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
