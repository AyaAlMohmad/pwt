<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Admin list shape for a Promotion row (lighter — no products / categories
 * eager-load on list endpoint to keep N+1 contained).
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminPromotion',
    required: ['id', 'code', 'name', 'type', 'value', 'applies_to', 'is_active', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'code', type: 'string', maxLength: 64, example: 'SUMMER15'),
        new OA\Property(
            property: 'name',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string'),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'type', type: 'string', enum: ['percent', 'fixed']),
        new OA\Property(property: 'value', type: 'string', example: '15.00'),
        new OA\Property(property: 'max_discount_amount', type: 'string', nullable: true, example: '200.00'),
        new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR']),
        new OA\Property(property: 'min_order_amount', type: 'string', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'per_user_limit', type: 'integer', nullable: true),
        new OA\Property(property: 'total_limit', type: 'integer', nullable: true),
        new OA\Property(property: 'applies_to', type: 'string', enum: ['all', 'products', 'categories']),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'upcoming', 'expired', 'inactive', 'trashed']),
        new OA\Property(property: 'redemptions_count', type: 'integer', example: 0),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminPromotion
{
    // Documentation-only.
}
