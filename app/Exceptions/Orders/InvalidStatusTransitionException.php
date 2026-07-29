<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\AppException;

/**
 * Raised when an order status transition is attempted outside the
 * allowed state-machine map. Mapped to HTTP 409
 * `ERR_INVALID_STATUS_TRANSITION` by the global handler.
 *
 * See docs/architecture/orders.md § 2.3 and § 5.4.
 */
final class InvalidStatusTransitionException extends AppException
{
    public static function from(OrderStatus $current, OrderStatus $target): self
    {
        return new self(
            __('orders.invalid_status_transition', [
                'from' => $current->value,
                'to' => $target->value,
            ]),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }

    /**
     * Customer-friendly variants used by the cancel endpoint. The
     * generic transition message reads too engineer-y for the customer
     * apps; this helper picks a kinder copy.
     */
    public static function cannotCancel(OrderStatus $current): self
    {
        $key = $current === OrderStatus::Cancelled
            ? 'orders.cancel_already_cancelled'
            : 'orders.cancel_not_allowed';

        return new self(
            __($key),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }
}
