<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use App\Enums\OrderStatus;
use App\Exceptions\AppException;

/**
 * Raised when `POST /api/v1/payments/initiate` is called for an order
 * whose status is not `pending`. Mapped to HTTP 422 `ERR_ORDER_NOT_PAYABLE`.
 *
 * See docs/architecture/payments.md § 3.1.2 / § 5.
 */
final class OrderNotPayableException extends AppException
{
    public static function from(OrderStatus $current): self
    {
        return new self(
            __('payments.not_payable'),
            422,
            'ERR_ORDER_NOT_PAYABLE',
            [
                'order_id' => [
                    __('payments.not_payable_with_status', ['status' => $current->value]),
                ],
            ],
        );
    }
}
