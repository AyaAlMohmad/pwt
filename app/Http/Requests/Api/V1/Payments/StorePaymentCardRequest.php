<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Payments;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/me/payment-cards.
 *
 * Accepts the raw UI fields (card_number, expiry, cvc) and derives
 * last_four / expiry_month / expiry_year automatically.
 */
final class StorePaymentCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $patches = [];

        $cardNumber = preg_replace('/\D/', '', (string) $this->input('card_number'));

        if ($cardNumber !== '' && strlen($cardNumber) >= 4) {
            $patches['last_four'] = substr($cardNumber, -4);
            $patches['card_number'] = $cardNumber;
        }

        $expiry = $this->input('expiry');
        if ($expiry !== null && is_string($expiry)) {
            $parts = explode('/', $expiry);
            if (count($parts) === 2) {
                $patches['expiry_month'] = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);
                $patches['expiry_year'] = strlen(trim($parts[1])) === 2
                    ? '20'.trim($parts[1])
                    : trim($parts[1]);
            }
        }

        // Accept card_cvc as an alias for cvc
        $cardCvc = $this->input('card_cvc');
        if ($cardCvc !== null && $cardCvc !== '') {
            $patches['cvc'] = $cardCvc;
        }

        if ($patches !== []) {
            $this->merge($patches);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'card_number' => ['nullable', 'string', 'regex:/^\d{13,19}$/'],
            'expiry' => ['nullable', 'string', 'regex:/^(0[1-9]|1[0-2])\s*\/\s*\d{2,4}$/'],
            'cvc' => ['nullable', 'string', 'regex:/^\d{3,4}$/'],
            'brand' => ['nullable', 'string', 'max:30'],
            'last_four' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'card_number_hash' => ['nullable', 'string', 'max:255'],
            'expiry_month' => ['required', 'string', 'size:2', 'regex:/^(0[1-9]|1[0-2])$/'],
            'expiry_year' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'cardholder_name' => ['nullable', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        // Never persist raw card_number or expiry
        unset($validated['card_number'], $validated['expiry']);

        return $validated;
    }
}
