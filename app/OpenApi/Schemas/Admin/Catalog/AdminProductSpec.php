<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Admin shape for a product spec row — raw bilingual `label` / `value`.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminProductSpec',
    required: ['id', 'label', 'value', 'display_order'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 71),
        new OA\Property(
            property: 'label',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string', example: 'Filtration stages'),
                new OA\Property(property: 'ar', type: 'string', nullable: true, example: 'مراحل الترشيح'),
            ],
        ),
        new OA\Property(
            property: 'value',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string', example: '5'),
                new OA\Property(property: 'ar', type: 'string', nullable: true, example: '٥'),
            ],
        ),
        new OA\Property(property: 'display_order', type: 'integer', example: 0),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminProductSpec
{
    // Documentation-only.
}
