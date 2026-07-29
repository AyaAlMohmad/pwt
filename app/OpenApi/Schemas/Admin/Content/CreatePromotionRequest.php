<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Request body for POST /api/v1/admin/promotions.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'CreatePromotionRequest',
    required: ['code', 'name', 'type', 'value', 'applies_to'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 64, example: 'SUMMER15'),
        new OA\Property(
            property: 'name',
            type: 'object',
            required: ['en'],
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 255),
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
        new OA\Property(property: 'value', type: 'number', format: 'float', minimum: 0.01, maximum: 999999.99),
        new OA\Property(property: 'max_discount_amount', type: 'number', format: 'float', nullable: true, description: 'Required when type=percent; forbidden when type=fixed.'),
        new OA\Property(property: 'currency', type: 'string', enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR'], nullable: true, description: 'Required when type=fixed.'),
        new OA\Property(property: 'min_order_amount', type: 'number', format: 'float', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'per_user_limit', type: 'integer', minimum: 1, maximum: 1000, nullable: true),
        new OA\Property(property: 'total_limit', type: 'integer', minimum: 1, maximum: 1000000, nullable: true),
        new OA\Property(property: 'applies_to', type: 'string', enum: ['all', 'products', 'categories']),
        new OA\Property(property: 'product_ids', type: 'array', items: new OA\Items(type: 'integer'),
            description: 'Required when applies_to=products; forbidden otherwise.'),
        new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'integer'),
            description: 'Required when applies_to=categories; forbidden otherwise.'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ],
    type: 'object',
)]
final class CreatePromotionRequest
{
    // Documentation-only.
}
