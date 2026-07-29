<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Admin detail shape for a Promotion — adds applies_to pivot rows plus
 * the redemption aggregates.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminPromotionDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/AdminPromotion'),
        new OA\Schema(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'products',
                    type: 'array',
                    nullable: true,
                    items: new OA\Items(ref: '#/components/schemas/AdminPromotionProductRef'),
                    description: 'Populated when applies_to=products.',
                ),
                new OA\Property(
                    property: 'categories',
                    type: 'array',
                    nullable: true,
                    items: new OA\Items(ref: '#/components/schemas/AdminPromotionCategoryRef'),
                    description: 'Populated when applies_to=categories.',
                ),
                new OA\Property(property: 'total_amount_redeemed', type: 'string', example: '0.00'),
                new OA\Property(property: 'unique_users_count', type: 'integer', example: 0),
            ],
        ),
    ],
)]
final class AdminPromotionDetail
{
    // Documentation-only.
}
