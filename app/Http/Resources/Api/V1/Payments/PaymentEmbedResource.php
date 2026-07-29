<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tiny embedded payment shape used inside the OrderDetail response
 * (the `order.payment` key). Exposes only the fields a customer needs
 * to render "Paid via card on YYYY-MM-DD" — never gateway internals.
 *
 * @property-read Payment $resource
 */
final class PaymentEmbedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var Payment $payment */
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'public_id' => $payment->public_id,
            'status' => $payment->status->value,
            'payment_method' => $payment->payment_method->value,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'captured_at' => $payment->captured_at?->toIso8601String(),
        ];
    }
}
