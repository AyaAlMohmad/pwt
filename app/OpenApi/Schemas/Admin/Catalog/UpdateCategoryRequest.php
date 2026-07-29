<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Request body for PUT /api/v1/admin/categories/{category}.
 *
 * PUT semantics — every translatable field must be re-sent (AR locale
 * falls back to the existing stored value when omitted).
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'UpdateCategoryRequest',
    required: ['code', 'name'],
    properties: [
        new OA\Property(property: 'code', type: 'string', minLength: 2, maxLength: 80, pattern: '^[a-z0-9-]+$', example: 'kitchen-filters'),
        new OA\Property(
            property: 'name',
            type: 'object',
            required: ['en'],
            properties: [
                new OA\Property(property: 'en', type: 'string', minLength: 2, maxLength: 120),
                new OA\Property(property: 'ar', type: 'string', nullable: true, minLength: 2, maxLength: 120),
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
        new OA\Property(property: 'display_order', type: 'integer', minimum: 0, maximum: 65535),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'parent_id', type: 'integer', nullable: true),
    ],
    type: 'object',
)]
final class UpdateCategoryRequest
{
    // Documentation-only.
}
