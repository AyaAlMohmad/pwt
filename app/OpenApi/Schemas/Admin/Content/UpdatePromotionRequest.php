<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Request body for PUT /api/v1/admin/promotions/{promotion}.
 *
 * `code` is immutable post-creation and silently dropped server-side.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'UpdatePromotionRequest',
    properties: [
        new OA\Property(
            property: 'name',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'ar', type: 'string', maxLength: 255, nullable: true),
            ],
        ),
        new OA\Property(
            property: 'description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 2000, nullable: true),
                new OA\Property(property: 'ar', type: 'string', maxLength: 2000, nullable: true),
            ],
        ),
        new OA\Property(property: 'type', type: 'string', enum: ['percent', 'fixed']),
        new OA\Property(property: 'value', type: 'number', format: 'float'),
        new OA\Property(property: 'max_discount_amount', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'currency', type: 'string', enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR'], nullable: true),
        new OA\Property(property: 'min_order_amount', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'per_user_limit', type: 'integer', nullable: true),
        new OA\Property(property: 'total_limit', type: 'integer', nullable: true),
        new OA\Property(property: 'applies_to', type: 'string', enum: ['all', 'products', 'categories']),
        new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
    type: 'object',
)]
final class UpdatePromotionRequest
{
    // Documentation-only.
}
