<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use App\Exceptions\AppException;

/**
 * Raised when one or more line items cannot be fulfilled (out of stock,
 * currency mismatch, quote-only product, missing active price row,
 * etc.). Mapped to HTTP 422 `ERR_PRODUCT_UNAVAILABLE` with per-line
 * errors under `errors.items.{index}`.
 *
 * See docs/architecture/orders.md § 3.1 / § 5.1.
 */
final class ProductUnavailableException extends AppException
{
    /**
     * @param  array<string, array<int, string>>  $perItemErrors  Already-keyed `items.{i}` => messages.
     */
    public static function fromItems(array $perItemErrors): self
    {
        return new self(
            __('orders.product_unavailable'),
            422,
            'ERR_PRODUCT_UNAVAILABLE',
            $perItemErrors,
        );
    }
}
