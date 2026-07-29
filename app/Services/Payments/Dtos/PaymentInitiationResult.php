<?php

declare(strict_types=1);

namespace App\Services\Payments\Dtos;

use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;

/**
 * Result of a successful {@see PaymentGateway::initiate}
 * call. Driver-agnostic so the calling {@see PaymentService}
 * can persist the same fields regardless of which gateway returned them.
 *
 * Supports both redirect (hosted-checkout URL) and embedded-checkout
 * (sessionId / token) flows.  Only one set of fields is populated
 * depending on the driver configuration.
 *
 * @see docs/architecture/payments.md § 8.2
 */
final readonly class PaymentInitiationResult
{
    /**
     * @param  array<string, mixed>  $raw  Driver-specific raw response for forensic logging.
     */
    public function __construct(
        public ?string $clientPaymentUrl = null,
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayReference = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $sessionId = null,
        public ?string $paymentReference = null,
        public ?string $paymentToken = null,
        public array $raw = [],
    ) {}
}
