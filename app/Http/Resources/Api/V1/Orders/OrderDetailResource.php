<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Orders;

use App\Http\Resources\Api\V1\Addresses\AddressResource;
use App\Http\Resources\Api\V1\Payments\PaymentEmbedResource;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Full single-order shape returned by `GET /orders/{id}`.
 *
 * Extends {@see OrderResource} with `items[]`, `status_history[]`,
 * full `delivery_address` / `billing_address`, full `promotion`, and
 * `notes`. `payment` is wired in Phase 9 — null in Phase 8.
 *
 * @property-read Order $resource
 */
final class OrderDetailResource extends OrderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $base = parent::toArray($request);

        $base['items'] = $order->relationLoaded('items')
            ? OrderItemResource::collection($order->items)->resolve($request)
            : [];

        // Phase 17 — filter out admin-internal notes-only rows from the
        // customer surface (see docs/architecture/admin-orders.md § 9.4).
        // Internal rows are written by the admin /notes endpoint and the
        // admin /status PATCH with a note; they must never leak to the
        // customer-facing timeline.
        $base['status_history'] = $order->relationLoaded('statusHistory')
            ? OrderStatusHistoryResource::collection(
                $order->statusHistory->reject(static fn ($entry): bool => (bool) $entry->is_internal)->values(),
            )->resolve($request)
            : [];

        $base['delivery_address'] = $order->relationLoaded('deliveryAddress') && $order->deliveryAddress !== null
            ? (new AddressResource($order->deliveryAddress))->resolve($request)
            : null;

        $base['billing_address'] = $order->relationLoaded('billingAddress') && $order->billingAddress !== null
            ? (new AddressResource($order->billingAddress))->resolve($request)
            : null;

        $base['promotion'] = $this->resolvePromotionFull($order);
        $base['notes'] = $order->notes;

        // Phase 9 — embed the most recent non-cancelled Payment when
        // the `payments` relation is eager-loaded. Falls back to null
        // for orders that have not been paid yet (or older orders
        // pre-Phase 9).
        $payment = $order->relationLoaded('payments') ? $order->latestPayment() : null;
        $base['payment'] = $payment !== null
            ? (new PaymentEmbedResource($payment))->resolve($request)
            : null;

        return $base;
    }

    /**
     * Full promotion embed (vs the small one in the list resource).
     *
     * @return array<string, mixed>|null
     */
    private function resolvePromotionFull(Order $order): ?array
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
            'type' => $promo->type->value,
            'value' => (float) $promo->value,
            'applied_amount' => (string) $order->discount_amount,
            'currency' => $order->currency,
        ];
    }
}
