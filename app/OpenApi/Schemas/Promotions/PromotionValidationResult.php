<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Promotions;

use App\Enums\PromotionReasonCode;
use OpenApi\Attributes as OA;

/**
 * The `data` shape returned by `POST /api/v1/promotions/validate`.
 * Always wrapped inside the standard success envelope — even when
 * `valid=false` (Stripe-style soft-fail; docs/architecture/promotions.md
 * § 5.1).
 */
#[OA\Schema(
    schema: 'PromotionValidationResult',
    required: ['valid', 'code', 'reason_code', 'message'],
    properties: [
        new OA\Property(property: 'valid', type: 'boolean', example: true),
        new OA\Property(property: 'code', type: 'string', maxLength: 40, example: 'SUMMER15'),
        new OA\Property(
            property: 'reason_code',
            type: 'string',
            nullable: true,
            enum: PromotionReasonCode::class,
            example: null,
            description: 'Null when valid=true. Otherwise one of the enumerated soft-fail reasons.',
        ),
        new OA\Property(property: 'message', type: 'string', example: 'Promo code applied — you saved AED 75.00.'),
        new OA\Property(
            property: 'discount',
            type: 'object',
            nullable: true,
            required: ['type', 'value', 'applies_to'],
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['percent', 'fixed'], example: 'percent'),
                new OA\Property(property: 'value', type: 'number', format: 'float', example: 15.00),
                new OA\Property(property: 'currency', type: 'string', nullable: true, example: null),
                new OA\Property(property: 'applies_to', type: 'string', enum: ['all', 'products', 'categories'], example: 'all'),
            ],
        ),
        new OA\Property(property: 'applied_amount', type: 'number', format: 'float', nullable: true, example: 75.00),
        new OA\Property(property: 'currency', type: 'string', nullable: true, example: 'AED'),
        new OA\Property(property: 'promotion_id', type: 'integer', nullable: true, example: 1),
    ],
    type: 'object',
)]
final class PromotionValidationResult
{
    // Documentation-only.
}
