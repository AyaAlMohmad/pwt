<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateOrderItemInput',
    required: ['product_id', 'quantity', 'term'],
    properties: [
        new OA\Property(property: 'product_id', type: 'integer', example: 5),
        new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: 50, example: 1),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc'], example: 'buy'),
    ],
    type: 'object',
)]
final class CreateOrderItemInput
{
    // Documentation-only.
}
