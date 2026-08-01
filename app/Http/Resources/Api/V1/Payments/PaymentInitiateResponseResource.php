<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Response shape for `POST /api/v1/payments/initiate`.
 *
 * Extends the base resource with:
 *  - Embedded-checkout session fields (session_id, payment_reference,
 *    payment_token) for online methods.
 *  - A small embedded `order` snippet on the COD branch so the client
 *    doesn't need a follow-up `GET /orders/{id}` to see the
 *    auto-transitioned `confirmed` status.
 */
final class PaymentInitiateResponseResource extends PaymentResource
{
    /**
     * @var array<string, mixed>
     */
    public array $session = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        /** @var Payment $payment */
        $payment = $this->resource;

        if ($payment->payment_method === PaymentMethod::Cod && $payment->relationLoaded('order')) {
            $order = $payment->order;
            $base['order'] = $order !== null ? [
                'id' => $order->id,
                'public_id' => $order->public_id,
                'status' => $order->status->value,
            ] : null;
        }

        if ($this->session !== []) {
            $base['session_id'] = $this->session['session_id'] ?? null;
            $base['payment_reference'] = $this->session['payment_reference'] ?? null;
            $base['payment_token'] = $this->session['payment_token'] ?? null;
            $base['client_secret'] = $this->session['client_secret'] ?? null;
        }

        return $base;
    }
}
