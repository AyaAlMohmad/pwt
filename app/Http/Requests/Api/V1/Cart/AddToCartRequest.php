<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Cart;

use App\Enums\ProductTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/cart/items — add product to cart.
 */
final class AddToCartRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'term' => ['required', 'string', Rule::in(ProductTerm::values())],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.required' => __('cart.product_id_required'),
            'product_id.exists' => __('cart.product_invalid'),
            'term.required' => __('cart.term_required'),
            'term.in' => __('cart.term_invalid'),
            'quantity.required' => __('cart.quantity_required'),
            'quantity.min' => __('cart.quantity_min'),
            'quantity.max' => __('cart.quantity_max'),
        ];
    }
}
