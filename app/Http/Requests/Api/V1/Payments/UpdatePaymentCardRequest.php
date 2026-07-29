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
            'brand' => ['sometimes', 'nullable', 'string', 'max:30'],
            'last_four' => ['sometimes', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'card_number_hash' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cvc' => ['sometimes', 'nullable', 'string', 'regex:/^\d{3,4}$/'],
            'expiry_month' => ['sometimes', 'string', 'size:2', 'regex:/^(0[1-9]|1[0-2])$/'],
            'expiry_year' => ['sometimes', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'cardholder_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
