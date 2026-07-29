<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Request body for POST /api/v1/admin/categories.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'CreateCategoryRequest',
    required: ['code', 'name'],
    properties: [
        new OA\Property(property: 'code', type: 'string', minLength: 2, maxLength: 80, pattern: '^[a-z0-9-]+$', example: 'counter-top-filters'),
        new OA\Property(
            property: 'name',
            type: 'object',
            required: ['en'],
            properties: [
                new OA\Property(property: 'en', type: 'string', minLength: 2, maxLength: 120, example: 'Counter-top Filters'),
                new OA\Property(property: 'ar', type: 'string', nullable: true, minLength: 2, maxLength: 120, example: 'فلاتر مرتفعة'),
            ],
        ),
        new OA\Property(
            property: 'description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true, maxLength: 5000),
                new OA\Property(property: 'ar', type: 'string', nullable: true, maxLength: 5000),
            ],
        ),
        new OA\Property(property: 'image_url', type: 'string', nullable: true, maxLength: 500),
        new OA\Property(property: 'display_order', type: 'integer', minimum: 0, maximum: 65535, example: 0),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
    ],
    type: 'object',
)]
final class CreateCategoryRequest
{
    // Documentation-only.
}
