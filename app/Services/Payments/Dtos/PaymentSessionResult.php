<?php

declare(strict_types=1);

namespace App\Services\Payments\Dtos;

use App\Services\Payments\PaymentGateway;

/**
 * Result of a successful {@see PaymentGateway::createSession} call.
 * Used for embedded-checkout flows (PayFort Merchant Page 2.0, etc.)
 * where the frontend needs a session token rather than a redirect URL.
 *
 * @see docs/architecture/payments.md § 8.2
 */
final readonly class PaymentSessionResult
{
    /**
     * @param  array<string, mixed>  $raw  Driver-specific raw response for forensic logging.
     */
    public function __construct(
        public string $sessionId,
        public string $paymentReference,
        public ?string $paymentToken,
        public ?string $gatewayTransactionId = null,
        public array $raw = [],
    ) {}
}
