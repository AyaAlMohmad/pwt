<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/cart/items/{id} — update item quantity.
 */
final class UpdateCartItemRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => __('cart.quantity_required'),
            'quantity.min' => __('cart.quantity_min'),
            'quantity.max' => __('cart.quantity_max'),
        ];
    }
}
