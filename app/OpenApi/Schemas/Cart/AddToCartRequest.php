<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Cart;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AddToCartRequest',
    type: 'object',
    required: ['product_id', 'term', 'quantity'],
    properties: [
        new OA\Property(property: 'product_id', type: 'integer', minimum: 1, example: 3),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc'], example: 'buy'),
        new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: 99, default: 1, example: 2),
    ],
)]
final class AddToCartRequest
{
    // Documentation-only.
}
