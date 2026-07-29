<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Embedded product reference on AdminPromotionDetail.products[*].
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminPromotionProductRef',
    required: ['id', 'code', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(
            property: 'name',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string'),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
    ],
    type: 'object',
)]
final class AdminPromotionProductRef
{
    // Documentation-only.
}
