<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Orders;

use App\Http\Resources\Api\V1\Addresses\AddressResource;
use App\Models\Order;
use App\Models\OrderInstallation;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Full single-order shape returned by GET /api/v1/admin/orders/{id} and
 * all admin write endpoints.
 *
 * Extends the list resource with:
 *  - full money columns,
 *  - `items[]` via {@see AdminOrderItemResource},
 *  - `status_history[]` (UNFILTERED — admin sees internal notes too),
 *  - full `customer` embed with workload counts,
 *  - full `delivery_address` / `billing_address` (admin AddressResource
 *    re-uses the customer one),
 *  - `applied_promotion`, `payment`, `installation` blocks.
 *
 * @property-read Order $resource
 */
final class AdminOrderDetailResource extends AdminOrderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $base = parent::toArray($request);

        $base['subtotal_amount'] = (string) $order->subtotal_amount;
        $base['discount_amount'] = (string) $order->discount_amount;
        $base['tax_amount'] = (string) $order->tax_amount;
        $base['tax_rate'] = (float) $order->tax_rate;
        $base['shipping_amount'] = (string) $order->shipping_amount;
        $base['notes'] = $order->notes;
        $base['cancellation_reason'] = $order->cancellation_reason;
        $base['cancelled_by'] = $this->resolveCancelledBy($order);

        $base['customer_name'] = $order->customer_name;
        $base['customer_email'] = $order->customer_email;
        $base['customer_phone'] = $order->customer_phone;

        $base['customer'] = $this->resolveCustomerFull($order);

        $base['items'] = $order->relationLoaded('items')
            ? AdminOrderItemResource::collection($order->items)->resolve($request)
            : [];

        $base['status_history'] = $order->relationLoaded('statusHistory')
            ? AdminOrderStatusHistoryResource::collection($order->statusHistory)->resolve($request)
            : [];

        $base['delivery_address'] = $order->relationLoaded('deliveryAddress') && $order->deliveryAddress !== null
            ? (new AddressResource($order->deliveryAddress))->resolve($request)
            : null;

        $base['billing_address'] = $order->relationLoaded('billingAddress') && $order->billingAddress !== null
            ? (new AddressResource($order->billingAddress))->resolve($request)
            : null;

        $base['applied_promotion'] = $this->resolvePromotion($order);
        $base['payment'] = $this->resolvePayment($order);
        $base['installation'] = $this->resolveInstallation($order);

        return $base;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCustomerFull(Order $order): ?array
    {
        if (! $order->relationLoaded('user') || $order->user === null) {
            return null;
        }

        $user = $order->user;
        $phoneE164 = $user->phone !== null
            ? $user->phone_country_code.$user->phone
            : null;

        $ordersCount = $user->getAttribute('orders_count');
        $ticketsCount = $user->getAttribute('support_tickets_count');
        $machinesCount = $user->getAttribute('machines_count');

        $address = null;
        if ($order->relationLoaded('deliveryAddress') && $order->deliveryAddress !== null) {
            $address = $order->deliveryAddress;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_country_code' => $user->phone_country_code,
            'phone_e164' => $phoneE164,
            'locale' => $user->locale,
            'is_active' => (bool) $user->is_active,
            'deleted_at' => $user->deleted_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'orders_count' => is_numeric($ordersCount) ? (int) $ordersCount : null,
            'support_tickets_count' => is_numeric($ticketsCount) ? (int) $ticketsCount : null,
            'machines_count' => is_numeric($machinesCount) ? (int) $machinesCount : null,
            'address' => $address !== null
                ? (new AddressResource($address))->resolve()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCancelledBy(Order $order): ?array
    {
        if (! $order->relationLoaded('cancelledBy') || $order->cancelledBy === null) {
            return null;
        }
        /** @var User $by */
        $by = $order->cancelledBy;

        return [
            'id' => $by->id,
            'name' => $by->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePromotion(Order $order): ?array
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

    /**
     * Latest non-cancelled payment with the admin-visible fields
     * (gateway_reference exposed, unlike the customer surface).
     *
     * @return array<string, mixed>|null
     */
    private function resolvePayment(Order $order): ?array
    {
        if (! $order->relationLoaded('payments')) {
            return null;
        }
        $payment = $order->latestPayment();
        if (! $payment instanceof Payment) {
            return null;
        }

        $events = $payment->relationLoaded('events')
            ? $payment->events->map(static fn ($event): array => [
                'id' => $event->id,
                'type' => $event->type,
                'occurred_at' => $event->created_at?->toIso8601String(),
            ])->values()->all()
            : [];

        return [
            'id' => $payment->id,
            'public_id' => $payment->public_id,
            'status' => $payment->status->value,
            'method' => $payment->payment_method->value,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'gateway' => $payment->gateway,
            'gateway_reference' => $payment->gateway_reference,
            'captured_at' => $payment->captured_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'cancelled_at' => $payment->cancelled_at?->toIso8601String(),
            'failure_reason' => $payment->failure_reason,
            'events' => $events,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveInstallation(Order $order): ?array
    {
        if (! $order->relationLoaded('installation') || $order->installation === null) {
            return null;
        }
        /** @var OrderInstallation $inst */
        $inst = $order->installation;
        $technician = $inst->relationLoaded('technician') ? $inst->technician : null;

        return [
            'id' => $inst->id,
            'status' => $inst->status->value,
            'scheduled_for_at' => $inst->scheduled_for_at->toIso8601String(),
            'technician' => $technician !== null
                ? ['id' => $technician->id, 'name' => $technician->name]
                : null,
            'installation_notes' => $inst->installation_notes,
            'address_snapshot' => $inst->address_snapshot,
            'completed_at' => $inst->completed_at?->toIso8601String(),
        ];
    }
}
