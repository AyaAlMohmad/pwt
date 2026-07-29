<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Request body for POST /api/v1/admin/products/{product}/images.
 *
 * `file_ids` must each reference a non-trashed files row of type
 * `product_image`. Ownership is intentionally NOT checked (any admin
 * with products.manage-images can use any product_image — see
 * docs/architecture/admin-catalog.md § 9.3).
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AttachProductImageRequest',
    required: ['file_ids'],
    properties: [
        new OA\Property(
            property: 'file_ids',
            type: 'array',
            items: new OA\Items(type: 'integer', example: 412),
            description: 'Array of pre-uploaded product_image file ids to attach.',
        ),
        new OA\Property(
            property: 'alt_text',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true, maxLength: 255),
                new OA\Property(property: 'ar', type: 'string', nullable: true, maxLength: 255),
            ],
        ),
        new OA\Property(property: 'is_primary', type: 'boolean', example: false, description: 'When true, the previous primary is atomically demoted and the FIRST attached image becomes primary.'),
        new OA\Property(property: 'display_order', type: 'integer', minimum: 0, maximum: 65535),
    ],
    type: 'object',
)]
final class AttachProductImageRequest
{
    // Documentation-only.
}
