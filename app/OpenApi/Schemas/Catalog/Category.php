<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Category',
    required: ['id', 'code', 'name', 'is_active', 'display_order', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1, description: 'Stable identifier (used as the ?category_id= filter on /products).'),
        new OA\Property(property: 'code', type: 'string', maxLength: 80, example: 'counter-top-filters'),
        new OA\Property(property: 'name', type: 'string', example: 'Counter-top Filters', description: 'Translated to Accept-Language.'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Compact filters that sit on the kitchen counter.'),
        new OA\Property(property: 'image_url', type: 'string', nullable: true, example: 'https://api.pwt.local/storage/categories/counter-top.webp'),
        new OA\Property(property: 'products_count', type: 'integer', nullable: true, example: 4, description: 'Count of active products in the category. Included only when withCount was eager-loaded (detail endpoint).'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'display_order', type: 'integer', example: 1),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T09:00:00+04:00'),
    ],
    type: 'object',
)]
final class Category
{
    // Documentation-only.
}
