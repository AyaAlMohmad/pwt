<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Admin shape for a product image row.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminProductImage',
    required: ['id', 'url', 'is_primary', 'display_order'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 41),
        new OA\Property(property: 'file_id', type: 'integer', nullable: true, example: 412),
        new OA\Property(property: 'url', type: 'string', example: 'https://api.pwt.local/storage/products/PW100M/primary.webp'),
        new OA\Property(
            property: 'alt_text',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true, example: 'Front view'),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'is_primary', type: 'boolean', example: true),
        new OA\Property(property: 'display_order', type: 'integer', example: 0),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminProductImage
{
    // Documentation-only.
}
