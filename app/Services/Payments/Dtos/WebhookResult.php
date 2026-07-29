<?php

declare(strict_types=1);

namespace App\Services\Payments\Dtos;

use App\Enums\PaymentStatus;
use App\Services\Payments\PaymentService;

/**
 * Driver-agnostic webhook payload after the driver has parsed the raw
 * body. {@see PaymentService::handleWebhook}
 * consumes this DTO without knowing which gateway produced it.
 *
 * `newStatus` is the target PaymentStatus the webhook drives the row
 * to: `captured`, `failed`, `authorized`, or `cancelled`.
 *
 * @see docs/architecture/payments.md § 8.2
 */
final readonly class WebhookResult
{
    /**
     * @param  array<string, mixed>  $raw  Decoded body for forensic logging.
     */
    public function __construct(
        public string $gatewayTransactionId,
        public ?string $gatewayReference,
        public PaymentStatus $newStatus,
        public ?string $amount,
        public ?string $currency,
        public ?string $failureReason,
        public ?string $eventId,
        public array $raw = [],
    ) {}
}
