<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductPrice',
    required: ['id', 'term', 'amount', 'currency'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 91),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc'], example: 'buy'),
        new OA\Property(property: 'amount', type: 'string', example: '2500.00'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'AED'),
        new OA\Property(property: 'period', type: 'string', nullable: true, example: null),
        new OA\Property(property: 'min_term_months', type: 'integer', nullable: true, example: null),
    ],
    type: 'object',
)]
final class ProductPrice
{
    // Documentation-only.
}
