<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Admin shape for a Category row. Used both as the list item and the
 * single-resource detail body.
 *
 * Bilingual fields (`name`, `description`) are returned as raw objects
 * `{ "en": "...", "ar": "..." }` so the dashboard form can hydrate both
 * locales without a second request.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminCategoryDetail',
    required: [
        'id', 'code', 'name', 'parent_id', 'display_order',
        'is_active', 'created_at',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 80, example: 'counter-top-filters'),
        new OA\Property(
            property: 'name',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string', example: 'Counter-top Filters'),
                new OA\Property(property: 'ar', type: 'string', nullable: true, example: 'فلاتر مرتفعة'),
            ],
        ),
        new OA\Property(
            property: 'description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'image_url', type: 'string', nullable: true, example: 'https://api.pwt.local/storage/categories/counter-top.webp'),
        new OA\Property(property: 'parent_id', type: 'integer', nullable: true, example: null),
        new OA\Property(property: 'display_order', type: 'integer', example: 1),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true, example: null),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-02-01T08:00:00+00:00'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-04-12T11:30:00+00:00'),
        new OA\Property(property: 'products_count', type: 'integer', example: 8, description: 'Count across all is_active states.'),
        new OA\Property(property: 'active_products_count', type: 'integer', example: 6, description: 'Only included on detail responses.'),
    ],
    type: 'object',
)]
final class AdminCategoryDetail
{
    // Documentation-only.
}
