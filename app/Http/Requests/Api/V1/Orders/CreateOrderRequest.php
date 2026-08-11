<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Orders;

use App\Enums\PaymentMethod;
use App\Enums\ProductTerm;
use App\Models\User;
use App\Rules\ActiveProduct;
use App\Rules\OwnedAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/orders — body validation.
 *
 * Server-recomputed (never accepted from client):
 *   - all monetary fields (subtotal/discount/tax/shipping/total/unit_price)
 *   - currency (resolved from delivery address country)
 *   - tax_rate (resolved from billing address country)
 *   - status (always pending on create)
 *   - public_id, timestamps
 *
 * Idempotency-Key header is read by the controller; this Form Request
 * only validates the body.
 *
 * See docs/architecture/orders.md § 3.1.
 */
#[OA\RequestBody(
    request: 'CreateOrderRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/CreateOrderRequest'),
)]
final class CreateOrderRequest extends FormRequest
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

        // Normalise nested items[].term too so the `in:` rule passes for
        // mixed-case client submissions.
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
        /** @var User|null $user */
        $user = $this->user();
        $userId = $user?->getAuthIdentifier();
        $userIdInt = is_numeric($userId) ? (int) $userId : 0;

        return [
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', new ActiveProduct],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.term' => ['required', 'string', 'in:'.implode(',', ProductTerm::values())],

            // Customer contact info (user can override profile at checkout)
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_email' => ['required', 'string', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20'],

            // Delivery address: either select saved address OR type manually.
            'delivery_address_id' => [
                'nullable',
                'integer',
                'exists:addresses,id',
                new OwnedAddress($userIdInt),
            ],
            'delivery_address_line_1' => ['nullable', 'string', 'max:255'],
            'delivery_date' => ['required', 'date', 'after_or_equal:today'],
            'delivery_time_slot' => ['required', 'string', 'in:morning,afternoon,evening'],

            'billing_address_id' => [
                'nullable',
                'integer',
                'exists:addresses,id',
                new OwnedAddress($userIdInt),
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
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        return parent::validated($key, $default);
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
            'delivery_address_id.required_without' => __('orders.delivery_address_required'),
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
