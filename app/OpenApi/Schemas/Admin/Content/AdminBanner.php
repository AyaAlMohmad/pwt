<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Admin shape for a Banner row — used both as list item and single detail.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminBanner',
    required: ['id', 'code', 'placement', 'image_url', 'display_order', 'is_active', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'code', type: 'string', maxLength: 64, example: 'summer-2026-hero'),
        new OA\Property(property: 'placement', type: 'string', enum: ['hero', 'promo', 'sidebar', 'footer']),
        new OA\Property(
            property: 'title',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'subtitle',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'image_url', type: 'string'),
        new OA\Property(property: 'image_url_ar', type: 'string', nullable: true),
        new OA\Property(
            property: 'cta_label',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'cta_url', type: 'string', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'display_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'scheduled', 'expired', 'inactive', 'trashed']),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminBanner
{
    // Documentation-only.
}
