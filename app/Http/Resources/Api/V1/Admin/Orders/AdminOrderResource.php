<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Orders;

use App\Http\Resources\Api\V1\Addresses\AddressResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin list-shape of an {@see Order}.
 *
 * Returned by GET /api/v1/admin/orders index. Slimmer than the
 * customer-facing list resource:
 *  - drops `subtotal/discount/tax/shipping/tax_rate` (detail-only),
 *  - drops `cancellation_reason`, `notes`, `tracking_progress`,
 *  - embeds a small `customer` summary the admin dashboard renders in
 *    the table.
 *
 * Hidden: idempotency fields, FK ints (replaced by embeds), `deleted_at`
 * (replaced by `is_trashed`), `updated_at`.
 *
 * @property-read Order $resource
 */
class AdminOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'public_id' => $order->public_id,
            'display_name' => $order->displayName(),
            'status' => $order->status->value,
            'term' => $order->term->value,
            'payment_method' => $order->payment_method->value,
            'payment_status' => $this->resolvePaymentStatus($order),
            'currency' => $order->currency,
            'total_amount' => (string) $order->total_amount,
            'items_count' => $this->resolveItemsCount($order),
            'first_product' => $this->resolveFirstProduct($order),
            'remaining_items_count' => max(0, $this->resolveItemsCount($order) - 1),
            'customer' => $this->resolveCustomerSmall($order),
            'country_code' => $this->resolveCountryCode($order),
            'placed_at' => $order->created_at->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'scheduled_for_at' => $order->scheduled_for_at?->toIso8601String(),
            'installed_at' => $order->installed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'is_trashed' => $order->trashed(),
        ];
    }

    /**
     * Latest non-cancelled payment's status, or null.
     */
    protected function resolvePaymentStatus(Order $order): ?string
    {
        if (! $order->relationLoaded('payments')) {
            return null;
        }
        $payment = $order->latestPayment();

        return $payment?->status->value;
    }

    /**
     * Items count from `withCount('items')` falling back to a relation
     * count when the attribute isn't set.
     */
    protected function resolveItemsCount(Order $order): int
    {
        $value = $order->getAttribute('items_count');
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $order->items()->count();
    }

    /**
     * Small customer summary embed. Returns null when the relation
     * hasn't been eager-loaded — admins shouldn't be N+1'ing through
     * a 5k-row list anyway.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveCustomerSmall(Order $order): ?array
    {
        if (! $order->relationLoaded('user') || $order->user === null) {
            return null;
        }

        $user = $order->user;
        $phoneE164 = $user->phone !== null
            ? $user->phone_country_code.$user->phone
            : null;

        $address = null;
        if ($order->relationLoaded('deliveryAddress') && $order->deliveryAddress !== null) {
            $address = $order->deliveryAddress;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $phoneE164,
            'address' => $address !== null
                ? (new AddressResource($address))->resolve()
                : null,
        ];
    }

    protected function resolveCountryCode(Order $order): ?string
    {
        if (! $order->relationLoaded('deliveryAddress') || $order->deliveryAddress === null) {
            return null;
        }

        $country = $order->deliveryAddress->relationLoaded('country')
            ? $order->deliveryAddress->country
            : null;

        return $country?->code;
    }

    /**
     * First item summary for the admin list table.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveFirstProduct(Order $order): ?array
    {
        if (! $order->relationLoaded('items') || $order->items === null || $order->items->isEmpty()) {
            return null;
        }

        /** @var \App\Models\OrderItem $first */
        $first = $order->items->first();
        $locale = app()->getLocale();

        $name = $first->getTranslation('product_name_snapshot', $locale, false)
            ?: $first->getTranslation('product_name_snapshot', (string) config('app.fallback_locale', 'en'));

        return [
            'name' => $name,
            'plan' => $first->term->value,
            'quantity' => (int) $first->quantity,
        ];
    }
}
