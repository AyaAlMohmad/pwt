<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Orders;

use App\Http\Resources\Api\V1\Addresses\AddressResource;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing list / compact shape of an {@see Order}.
 *
 * Used by `GET /orders`, the 201 response of `POST /orders`, and the
 * 200 response of `POST /orders/{id}/cancel`. No `items[]`,
 * `status_history`, or `notes` — see
 * {@see OrderDetailResource} for the full shape.
 *
 * Per CLAUDE.md § 15 we never expose `user_id`, idempotency fields,
 * raw FK ids, or `cancelled_by_user_id` (PII).
 *
 * @property-read Order $resource
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'display_name' => $order->displayName(),
            'public_id' => $order->public_id,
            'status' => $order->status->value,
            'term' => $order->term->value,
            'payment_method' => $order->payment_method->value,
            'currency' => $order->currency,
            'subtotal_amount' => (string) $order->subtotal_amount,
            'discount_amount' => (string) $order->discount_amount,
            'tax_amount' => (string) $order->tax_amount,
            'tax_rate' => (float) $order->tax_rate,
            'shipping_amount' => (string) $order->shipping_amount,
            'total_amount' => (string) $order->total_amount,
            'items_count' => $this->resolveItemsCount($order),
            'first_product_name' => $this->resolveFirstProductName($order),
            'remaining_items_count' => $this->resolveRemainingItemsCount($order),
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'customer' => $this->resolveCustomer($order),
            'delivery_address' => $this->resolveDeliveryAddressSmall($order),
            'delivery_address_line_1' => $order->delivery_address_line_1,
            'delivery_date' => $order->delivery_date?->toDateString(),
            'delivery_time_slot' => $order->delivery_time_slot,
            'installation_preferred_time' => $order->installation_preferred_time,
            'payment_card' => $order->card_last_four !== null
                ? [
                    'last_four' => $order->card_last_four,
                    'expiry_month' => $order->card_expiry_month,
                    'expiry_year' => $order->card_expiry_year,
                    'cvc' => $order->card_cvc,
                ]
                : null,
            'promotion' => $this->resolvePromotionSmall($order),
            'placed_at' => $order->created_at->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'scheduled_for_at' => $order->scheduled_for_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'installed_at' => $order->installed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'tracking_progress' => $order->status->trackingProgress(),
            'is_cancellable' => $order->is_cancellable,
        ];
    }

    /**
     * Prefer `withCount('items')` (loaded as `items_count` attribute)
     * over a relation count lookup so the resource stays N+1-free on
     * list responses.
     */
    private function resolveItemsCount(Order $order): int
    {
        $value = $order->getAttribute('items_count');
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $order->items()->count();
    }

    /**
     * First product name from the order items (for compact list display).
     */
    private function resolveFirstProductName(Order $order): ?string
    {
        $locale = app()->getLocale();

        if ($order->relationLoaded('items')) {
            /** @var OrderItem|null $first */
            $first = $order->items->first();
            if ($first !== null) {
                return $first->getTranslation('product_name_snapshot', $locale, false)
                    ?: $first->getTranslation('product_name_snapshot', config('app.fallback_locale', 'en'));
            }
        }

        return null;
    }

    /**
     * Count of items remaining after the first one.
     */
    private function resolveRemainingItemsCount(Order $order): int
    {
        $count = $this->resolveItemsCount($order);

        return max(0, $count - 1);
    }

    /**
     * Customer info including the order's delivery address.
     */
    private function resolveCustomer(Order $order): array
    {
        $address = null;
        if ($order->relationLoaded('deliveryAddress') && $order->deliveryAddress !== null) {
            $address = $order->deliveryAddress;
        }

        return [
            'name' => $order->customer_name,
            'email' => $order->customer_email,
            'phone' => $order->customer_phone,
            'address' => $address !== null
                ? (new AddressResource($address))->resolve()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDeliveryAddressSmall(Order $order): ?array
    {
        if (! $order->relationLoaded('deliveryAddress') || $order->deliveryAddress === null) {
            return null;
        }

        return (new AddressResource($order->deliveryAddress))->resolve();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePromotionSmall(Order $order): ?array
    {
        if (! $order->relationLoaded('promotion') || $order->promotion === null) {
            return null;
        }

        $promo = $order->promotion;
        $locale = app()->getLocale();

        return [
            'id' => $promo->id,
            'code' => $promo->code,
            'name' => $promo->getTranslation('name', $locale),
        ];
    }
}
