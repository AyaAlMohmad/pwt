<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Promotions;

use OpenApi\Attributes as OA;

/**
 * Request body for `POST /api/v1/promotions/validate`.
 * See docs/architecture/promotions.md § 3.3.
 */
#[OA\Schema(
    schema: 'PromotionValidationRequest',
    required: ['code', 'subtotal', 'currency'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 40, example: 'SUMMER15', description: 'Normalised to uppercase + trimmed server-side.'),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float', minimum: 0, maximum: 9999999.99, example: 500.00),
        new OA\Property(property: 'currency', type: 'string', enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR'], example: 'AED'),
        new OA\Property(
            property: 'product_ids',
            type: 'array',
            maxItems: 50,
            items: new OA\Items(type: 'integer', minimum: 1),
            example: [1, 2, 3],
            description: 'Optional. Used only when the promotion has applies_to=products.',
        ),
        new OA\Property(
            property: 'category_ids',
            type: 'array',
            maxItems: 50,
            items: new OA\Items(type: 'integer', minimum: 1),
            example: [1],
            description: 'Optional. Used only when the promotion has applies_to=categories.',
        ),
    ],
    type: 'object',
)]
final class PromotionValidationRequest
{
    // Documentation-only.
}
