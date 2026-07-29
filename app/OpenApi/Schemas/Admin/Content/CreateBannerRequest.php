<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Request body for POST /api/v1/admin/banners.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'CreateBannerRequest',
    required: ['code', 'placement', 'image_url'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 64, example: 'summer-2026-hero'),
        new OA\Property(property: 'placement', type: 'string', enum: ['hero', 'promo', 'sidebar', 'footer']),
        new OA\Property(
            property: 'title',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 255),
                new OA\Property(property: 'ar', type: 'string', maxLength: 255, nullable: true),
            ],
        ),
        new OA\Property(
            property: 'subtitle',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 500, nullable: true),
                new OA\Property(property: 'ar', type: 'string', maxLength: 500, nullable: true),
            ],
        ),
        new OA\Property(property: 'image_url', type: 'string', maxLength: 2048, format: 'uri'),
        new OA\Property(property: 'image_url_ar', type: 'string', maxLength: 2048, format: 'uri', nullable: true),
        new OA\Property(
            property: 'cta_label',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 80, nullable: true),
                new OA\Property(property: 'ar', type: 'string', maxLength: 80, nullable: true),
            ],
        ),
        new OA\Property(property: 'cta_url', type: 'string', maxLength: 2048, format: 'uri', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'display_order', type: 'integer', minimum: 0, maximum: 9999),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ],
    type: 'object',
)]
final class CreateBannerRequest
{
    // Documentation-only.
}
