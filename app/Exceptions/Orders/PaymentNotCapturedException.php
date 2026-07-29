<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use App\Exceptions\AppException;

/**
 * Raised when an admin attempts to approve an order whose online
 * payment has not yet been captured.
 */
final class PaymentNotCapturedException extends AppException
{
    public static function make(): self
    {
        return new self(
            __('admin.orders.payment_not_captured'),
            409,
            'ERR_PAYMENT_NOT_CAPTURED',
        );
    }
}
