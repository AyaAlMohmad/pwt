<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Payments;

use Illuminate\Http\Request;

/**
 * Response shape for `POST /api/v1/payments/create-session`.
 *
 * Returns the embedded-checkout session plus the canonical
 * payment snippet so the client has everything needed to
 * initialise PayFort Merchant Page 2.0 (or equivalent) in the
 * review step.
 */
final class PaymentSessionResponseResource extends PaymentResource
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

        return array_merge($base, [
            'session_id' => $this->session['session_id'] ?? null,
            'payment_reference' => $this->session['payment_reference'] ?? null,
            'payment_token' => $this->session['payment_token'] ?? null,
            'amount' => $this->session['amount'] ?? null,
            'currency' => $this->session['currency'] ?? null,
        ]);
    }
}
