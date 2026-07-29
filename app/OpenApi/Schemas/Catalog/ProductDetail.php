<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

/**
 * Full product detail shape used by `GET /api/v1/products/{id}`.
 */
#[OA\Schema(
    schema: 'ProductDetail',
    required: ['id', 'code', 'name', 'is_quote_only', 'stock_status', 'is_featured', 'promotions_count', 'images', 'specs', 'prices', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'code', type: 'string', example: 'PW50M'),
        new OA\Property(property: 'name', type: 'string', example: 'PW50M Filtration Unit'),
        new OA\Property(property: 'short_description', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(
            property: 'benefits',
            type: 'array',
            nullable: true,
            items: new OA\Items(type: 'string'),
            example: ['Removes 99% of chlorine', '2-year filter life'],
        ),
        new OA\Property(property: 'primary_image_url', type: 'string', nullable: true),
        new OA\Property(property: 'images', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductImage')),
        new OA\Property(property: 'specs', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductSpec')),
        new OA\Property(property: 'prices', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductPrice')),
        new OA\Property(property: 'category', ref: '#/components/schemas/Category', nullable: true),
        new OA\Property(property: 'is_quote_only', type: 'boolean', example: false),
        new OA\Property(property: 'stock_status', type: 'string', enum: ['in_stock', 'out_of_stock', 'coming_soon', 'discontinued'], example: 'in_stock'),
        new OA\Property(property: 'is_featured', type: 'boolean', example: true),
        new OA\Property(property: 'promotions_count', type: 'integer', example: 0, description: 'Always 0 in Phase 6. Phase 7 (Promotions) will wire the real count.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T09:00:00+04:00'),
    ],
    type: 'object',
)]
final class ProductDetail
{
    // Documentation-only.
}
