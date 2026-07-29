<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use App\Exceptions\AppException;

/**
 * Raised when `POST /api/v1/payments/initiate` is called while an
 * active (non-expired) `initiated` Payment already exists for the
 * same order. Mapped to HTTP 409 `ERR_PAYMENT_ALREADY_INITIATED`.
 *
 * See docs/architecture/payments.md § 3.1.3 / § 5.
 */
final class PaymentAlreadyInitiatedException extends AppException
{
    public static function make(): self
    {
        return new self(
            __('payments.already_initiated'),
            409,
            'ERR_PAYMENT_ALREADY_INITIATED',
        );
    }
}
