<?php

declare(strict_types=1);

namespace App\Exceptions\Admin\Catalog;

use App\Exceptions\AppException;

/**
 * Raised when an admin sends `code` on a PUT /admin/products/{id}.
 *
 * The Form Request already declares `code` as `prohibited` on update,
 * but the service guards defensively in case a future code path bypasses
 * the request layer.
 *
 * Mapped to HTTP 422 `ERR_PRODUCT_CODE_IMMUTABLE` by the global handler.
 *
 * See docs/architecture/admin-catalog.md § 5.2 and § 9.4.
 */
final class ProductCodeImmutableException extends AppException
{
    public static function create(): self
    {
        return new self(
            __('admin.catalog.product_code_immutable'),
            422,
            'ERR_PRODUCT_CODE_IMMUTABLE',
            ['code' => [__('admin.catalog.product_code_immutable')]],
        );
    }
}
