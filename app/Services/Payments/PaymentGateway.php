<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Drivers\PayFortDriver;
use App\Services\Payments\Drivers\StubDriver;
use App\Services\Payments\Dtos\PaymentInitiationResult;
use App\Services\Payments\Dtos\PaymentSessionResult;
use App\Services\Payments\Dtos\WebhookResult;

/**
 * Contract every online payment gateway driver implements.
 *
 * COD is NOT a driver — {@see PaymentService::initiate} short-circuits
 * the COD branch without ever calling this interface.
 *
 * Implementations:
 *  - {@see StubDriver}   (dev / testing)
 *  - {@see PayFortDriver} (production)
 *  - {@see StripeDriver}  (production)
 *
 * Bound to the active driver in `AppServiceProvider::register()` via
 * `config('payment.default')`.
 *
 * @see docs/architecture/payments.md § 8.1
 */
interface PaymentGateway
{
    /**
     * Open a gateway-side checkout session for the given Payment and
     * return the customer-redirect URL plus the gateway-issued
     * correlator id.
     *
     * @param  array<string, mixed>  $context  Optional driver-specific extras (return_url, locale).
     *
     * @throws \RuntimeException When the gateway is unavailable / credentials missing.
     */
    public function initiate(Payment $payment, Order $order, array $context = []): PaymentInitiationResult;

    /**
     * Verify the HMAC signature on an inbound webhook BEFORE the body is
     * parsed. MUST NOT mutate any state. MUST use constant-time
     * comparison (`hash_equals`).
     *
     * @param  array<string, string>  $headers  Lower-cased header map.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool;

    /**
     * Parse a (pre-verified) decoded webhook payload into a driver-
     * agnostic DTO.
     *
     * @param  array<string, mixed>  $payload  Decoded body (associative array).
     */
    public function parseWebhookPayload(array $payload): WebhookResult;

    /**
     * Create an embedded-checkout session (SDK token, payment reference,
     * etc.) for the frontend to complete payment without leaving the site.
     *
     * @param  array<string, mixed>  $context  Optional driver-specific extras.
     *
     * @throws \RuntimeException When the gateway is unavailable / credentials missing.
     */
    public function createSession(Payment $payment, Order $order, array $context = []): PaymentSessionResult;

    /**
     * Driver identifier persisted on `payments.gateway`. One of:
     * `stub` | `payfort`.
     */
    public function name(): string;
}
