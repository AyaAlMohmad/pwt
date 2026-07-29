<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Payment;

/**
 * Lifecycle status of a {@see Payment}.
 *
 * `initiated` is the create-state for online flows (a payment URL was
 * returned to the customer; waiting for the gateway webhook). `captured`
 * is the success terminal — for COD orders a Payment is created directly
 * in this state. `failed` and `cancelled` are retryable terminals: the
 * customer may re-`POST /payments/initiate` against the same order and
 * a new Payment row will be opened.
 *
 * `refunded` is reserved for Phase 2 manual refund flows; no Phase 9
 * code path transitions into it.
 *
 * Full state machine: docs/architecture/payments.md § 2.
 */
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Terminal statuses cannot transition further within the webhook
     * state machine. The idempotency guard relies on this list.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Captured,
            self::Failed,
            self::Cancelled,
            self::Refunded,
        ], true);
    }

    /**
     * Retryable terminals: the customer may re-initiate on the same
     * order. See payments.md § 2.4.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled], true);
    }
}
