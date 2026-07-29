<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

/**
 * Compact product shape used by the paginated list endpoint
 * (`GET /api/v1/products`). The full detail shape is `ProductDetail`.
 */
#[OA\Schema(
    schema: 'Product',
    required: ['id', 'code', 'name', 'is_quote_only', 'stock_status', 'is_featured', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'code', type: 'string', maxLength: 80, example: 'PW50M'),
        new OA\Property(property: 'name', type: 'string', example: 'PW50M Filtration Unit'),
        new OA\Property(property: 'short_description', type: 'string', nullable: true, example: 'Compact 5-stage filter.'),
        new OA\Property(property: 'primary_image_url', type: 'string', nullable: true, example: 'https://api.pwt.local/storage/products/PW50M/placeholder.webp'),
        new OA\Property(property: 'starting_price', ref: '#/components/schemas/StartingPrice', nullable: true),
        new OA\Property(property: 'category', ref: '#/components/schemas/Category', nullable: true),
        new OA\Property(property: 'is_quote_only', type: 'boolean', example: false),
        new OA\Property(property: 'stock_status', type: 'string', enum: ['in_stock', 'out_of_stock', 'coming_soon', 'discontinued'], example: 'in_stock'),
        new OA\Property(property: 'is_featured', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T09:00:00+04:00'),
    ],
    type: 'object',
)]
final class Product
{
    // Documentation-only.
}
