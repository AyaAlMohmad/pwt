<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Admin shape for a price row (exposes is_active + timestamps).
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminProductPrice',
    required: ['id', 'term', 'amount', 'currency', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 91),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc'], example: 'buy'),
        new OA\Property(property: 'amount', type: 'string', example: '2500.00'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'AED'),
        new OA\Property(property: 'period', type: 'string', nullable: true, enum: ['monthly', 'yearly', null]),
        new OA\Property(property: 'min_term_months', type: 'integer', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminProductPrice
{
    // Documentation-only.
}
