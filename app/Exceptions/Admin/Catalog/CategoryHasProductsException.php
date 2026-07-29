<?php

declare(strict_types=1);

namespace App\Exceptions\Admin\Catalog;

use App\Exceptions\AppException;

/**
 * Raised when an admin tries to soft-delete a category that still
 * contains non-trashed product rows.
 *
 * Mapped to HTTP 422 `ERR_CATEGORY_HAS_PRODUCTS` by the global handler.
 *
 * See docs/architecture/admin-catalog.md § 5.1 and § 5.4.
 */
final class CategoryHasProductsException extends AppException
{
    public static function create(int $productsCount): self
    {
        return new self(
            __('admin.catalog.category_has_products'),
            422,
            'ERR_CATEGORY_HAS_PRODUCTS',
            ['products_count' => [(string) $productsCount]],
        );
    }
}
