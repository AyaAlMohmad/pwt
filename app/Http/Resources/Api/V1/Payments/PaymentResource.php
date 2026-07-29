<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing base shape of a {@see Payment}.
 *
 * Allowlisted fields only — `gateway_transaction_id`,
 * `gateway_reference`, `idempotency_key`, and `user_id` are never
 * exposed (per docs/architecture/payments.md § 4.2). `payment_url` is
 * only returned while the payment is in `initiated` state.
 *
 * @property-read Payment $resource
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var Payment $payment */
        $payment = $this->resource;

        $isInitiated = $payment->status === PaymentStatus::Initiated;
        $ttl = (int) config('payment.initiated_ttl_minutes', 30);

        return [
            'payment_id' => $payment->id,
            'public_id' => $payment->public_id,
            'order_id' => $payment->order_id,
            'status' => $payment->status->value,
            'payment_method' => $payment->payment_method->value,
            'gateway' => $payment->gateway,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'payment_url' => $isInitiated ? $payment->client_payment_url : null,
            'expires_at' => $isInitiated
                ? $payment->created_at->copy()->addMinutes($ttl)->toIso8601String()
                : null,
            'captured_at' => $payment->captured_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'cancelled_at' => $payment->cancelled_at?->toIso8601String(),
            'failure_reason' => $payment->failure_reason,
            'created_at' => $payment->created_at->toIso8601String(),
        ];
    }
}
