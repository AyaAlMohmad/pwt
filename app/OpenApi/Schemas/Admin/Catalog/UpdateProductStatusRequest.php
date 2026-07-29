<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Request body for PATCH /api/v1/admin/products/{product}/status.
 *
 * At least one field must be present.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'UpdateProductStatusRequest',
    properties: [
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_featured', type: 'boolean'),
        new OA\Property(property: 'is_quote_only', type: 'boolean'),
        new OA\Property(property: 'stock_status', type: 'string', enum: ['in_stock', 'out_of_stock', 'coming_soon', 'discontinued']),
    ],
    type: 'object',
)]
final class UpdateProductStatusRequest
{
    // Documentation-only.
}
