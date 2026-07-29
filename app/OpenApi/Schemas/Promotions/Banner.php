<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Promotions;

use OpenApi\Attributes as OA;

/**
 * Customer shape of a `banners` row.
 * See docs/architecture/promotions.md § 4.1.
 */
#[OA\Schema(
    schema: 'Banner',
    required: ['id', 'code', 'placement', 'image_url', 'display_order', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 80, example: 'summer-2026-hero'),
        new OA\Property(property: 'placement', type: 'string', enum: ['hero', 'promo', 'sidebar', 'footer'], example: 'hero'),
        new OA\Property(property: 'title', type: 'string', nullable: true, example: 'Cool, clean water for summer'),
        new OA\Property(property: 'subtitle', type: 'string', nullable: true, example: 'Save 15% on rentals through August'),
        new OA\Property(property: 'image_url', type: 'string', example: 'https://api.pwt.local/storage/banners/summer-2026/hero.webp', description: 'Locale-aware: returns image_url_ar when Accept-Language=ar and the AR variant is set; otherwise returns image_url.'),
        new OA\Property(property: 'cta_label', type: 'string', nullable: true, example: 'Browse rentals'),
        new OA\Property(property: 'cta_url', type: 'string', nullable: true, example: '/api/v1/products?term=rent'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true, example: '2026-06-01T00:00:00+04:00'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true, example: '2026-08-31T23:59:59+04:00'),
        new OA\Property(property: 'display_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ],
    type: 'object',
)]
final class Banner
{
    // Documentation-only.
}
