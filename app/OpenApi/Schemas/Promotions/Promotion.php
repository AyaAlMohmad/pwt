<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Promotions;

use OpenApi\Attributes as OA;

/**
 * Customer shape of a `promotions` row. Sensitive admin-only fields
 * (`total_limit`, `per_user_limit`) are masked.
 *
 * See docs/architecture/promotions.md § 4.2.
 */
#[OA\Schema(
    schema: 'Promotion',
    required: ['id', 'code', 'name', 'type', 'value', 'starts_at', 'ends_at', 'applies_to'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 40, example: 'SUMMER15'),
        new OA\Property(property: 'name', type: 'string', example: 'Summer Sale 2026'),
        new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Save 15% on all rentals.'),
        new OA\Property(property: 'type', type: 'string', enum: ['percent', 'fixed'], example: 'percent'),
        new OA\Property(property: 'value', type: 'number', format: 'float', example: 15.00),
        new OA\Property(property: 'max_discount_amount', type: 'number', format: 'float', nullable: true, example: 200.00),
        new OA\Property(property: 'currency', type: 'string', nullable: true, enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR'], example: null),
        new OA\Property(property: 'min_order_amount', type: 'number', format: 'float', nullable: true, example: 100.00),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', example: '2026-06-01T00:00:00+04:00'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', example: '2026-08-31T23:59:59+04:00'),
        new OA\Property(property: 'applies_to', type: 'string', enum: ['all', 'products', 'categories'], example: 'all'),
        new OA\Property(property: 'user_redemptions_remaining', type: 'integer', nullable: true, example: null, description: 'Non-null only when the request is authenticated and per_user_limit is set.'),
    ],
    type: 'object',
)]
final class Promotion
{
    // Documentation-only.
}
