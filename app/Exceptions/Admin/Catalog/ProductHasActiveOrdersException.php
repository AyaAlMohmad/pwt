<?php

declare(strict_types=1);

namespace App\Exceptions\Admin\Catalog;

use App\Exceptions\AppException;

/**
 * Raised when an admin tries to soft-delete a product that is still
 * referenced by at least one non-terminal order line item.
 *
 * Mapped to HTTP 422 `ERR_PRODUCT_HAS_ACTIVE_ORDERS` by the global
 * handler.
 *
 * Non-terminal statuses are the OrderStatus values that are mid-flow —
 * pending, confirmed, scheduled, out_for_delivery (per the orchestrator
 * decision recorded in docs/architecture/admin-catalog.md § 5.3).
 *
 * See docs/architecture/admin-catalog.md § 5.3 and § 10.3.
 */
final class ProductHasActiveOrdersException extends AppException
{
    public static function create(int $activeOrdersCount): self
    {
        return new self(
            __('admin.catalog.product_has_active_orders'),
            422,
            'ERR_PRODUCT_HAS_ACTIVE_ORDERS',
            ['active_orders_count' => [(string) $activeOrdersCount]],
        );
    }
}
