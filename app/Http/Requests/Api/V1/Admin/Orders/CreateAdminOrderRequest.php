<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Orders;

use App\Enums\PaymentMethod;
use App\Enums\ProductTerm;
use App\Rules\ActiveProduct;
use App\Rules\OwnedAddress;
use Illuminate\Foundation\Http\FormRequest;

final class CreateAdminOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $patches = [];

        if (is_string($this->input('term'))) {
            $patches['term'] = strtolower($this->input('term'));
        }
        if (is_string($this->input('payment_method'))) {
            $patches['payment_method'] = strtolower($this->input('payment_method'));
        }
        if (is_string($this->input('promo_code'))) {
            $patches['promo_code'] = strtoupper(trim($this->input('promo_code')));
            if ($patches['promo_code'] === '') {
                $patches['promo_code'] = null;
            }
        }

        $items = $this->input('items');
        if (is_array($items)) {
            $normalised = [];
            foreach ($items as $idx => $line) {
                if (! is_array($line)) {
                    $normalised[$idx] = $line;

                    continue;
                }
                if (isset($line['term']) && is_string($line['term'])) {
                    $line['term'] = strtolower($line['term']);
                }
                $normalised[$idx] = $line;
            }
            $patches['items'] = $normalised;
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
        $targetUserId = is_numeric($this->input('user_id')) ? (int) $this->input('user_id') : 0;

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],

            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', new ActiveProduct],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.term' => ['required', 'string', 'in:'.implode(',', ProductTerm::values())],

            // Customer contact info
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_email' => ['required', 'string', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20'],

            // Delivery address: either select customer's saved address OR type manually.
            'delivery_address_id' => [
                'nullable',
                'integer',
                'exists:addresses,id',
                new OwnedAddress($targetUserId),
            ],
            'delivery_address_line_1' => ['nullable', 'string', 'max:255'],
            'delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'delivery_time_slot' => ['required', 'string', 'in:morning,afternoon,evening'],

            'billing_address_id' => [
                'nullable',
                'integer',
                'exists:addresses,id',
                new OwnedAddress($targetUserId),
            ],
            'term' => ['required', 'string', 'in:'.implode(',', ProductTerm::values())],
            'payment_method' => ['required', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'installation_preferred_time' => ['nullable', 'string', 'in:morning,afternoon,evening,anytime'],

            // Forbidden — server recomputes these.
            'subtotal_amount' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'tax_rate' => ['prohibited'],
            'shipping_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'unit_price' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => __('orders.items_required'),
            'items.min' => __('orders.items_required'),
            'items.max' => __('orders.items_max'),
            'items.*.product_id.required' => __('orders.product_id_required'),
            'items.*.quantity.min' => __('orders.quantity_min'),
            'items.*.quantity.max' => __('orders.quantity_max'),
            'items.*.term.in' => __('orders.term_invalid'),
            'delivery_date.required' => __('orders.delivery_date_required'),
            'delivery_date.after_or_equal' => __('orders.delivery_date_invalid'),
            'delivery_time_slot.required' => __('orders.delivery_time_slot_required'),
            'delivery_time_slot.in' => __('orders.delivery_time_slot_invalid'),
            'customer_name.required' => __('orders.customer_name_required'),
            'customer_email.required' => __('orders.customer_email_required'),
            'customer_phone.required' => __('orders.customer_phone_required'),
            'term.in' => __('orders.term_invalid'),
            'payment_method.in' => __('orders.payment_method_invalid'),
            'notes.max' => __('orders.notes_max'),
            'subtotal_amount.prohibited' => __('orders.field_forbidden'),
            'discount_amount.prohibited' => __('orders.field_forbidden'),
            'tax_amount.prohibited' => __('orders.field_forbidden'),
            'tax_rate.prohibited' => __('orders.field_forbidden'),
            'shipping_amount.prohibited' => __('orders.field_forbidden'),
            'total_amount.prohibited' => __('orders.field_forbidden'),
            'currency.prohibited' => __('orders.field_forbidden'),
            'unit_price.prohibited' => __('orders.field_forbidden'),
        ];
    }
}
