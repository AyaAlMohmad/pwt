<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\AppException;

/**
 * Raised when an internal payment state transition is attempted
 * outside the allowed map (e.g. `captured → captured` via duplicate
 * non-idempotent webhook). Mapped to HTTP 409 `ERR_INVALID_PAYMENT_STATE`.
 *
 * See docs/architecture/payments.md § 2.3.
 */
final class InvalidPaymentStateException extends AppException
{
    public static function from(PaymentStatus $current, PaymentStatus $target): self
    {
        return new self(
            __('payments.invalid_state_transition', [
                'from' => $current->value,
                'to' => $target->value,
            ]),
            409,
            'ERR_INVALID_PAYMENT_STATE',
        );
    }
}
