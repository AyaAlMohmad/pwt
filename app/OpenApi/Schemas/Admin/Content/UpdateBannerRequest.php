<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Request body for PUT /api/v1/admin/banners/{banner}.
 *
 * `code` is immutable post-creation and silently dropped server-side.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'UpdateBannerRequest',
    properties: [
        new OA\Property(property: 'placement', type: 'string', enum: ['hero', 'promo', 'sidebar', 'footer']),
        new OA\Property(
            property: 'title',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 255, nullable: true),
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
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
    type: 'object',
)]
final class UpdateBannerRequest
{
    // Documentation-only.
}
