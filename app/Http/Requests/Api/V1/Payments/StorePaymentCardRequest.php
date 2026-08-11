<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/me/payment-cards.
 *
 * Accepts only the Stripe Payment Method ID. Card details and customer
 * are fetched/created by the backend. Raw PAN/CVV are never accepted.
 */
final class StorePaymentCardRequest extends FormRequest
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
            'payment_method_id' => ['required', 'string', 'regex:/^pm_[A-Za-z0-9]+$/', 'max:255'],
            'cardholder_name' => ['nullable', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        return parent::validated($key, $default);
    }
}
