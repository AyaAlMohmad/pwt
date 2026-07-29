<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Cart;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateCartItemRequest',
    type: 'object',
    required: ['quantity'],
    properties: [
        new OA\Property(property: 'quantity', type: 'integer', minimum: 0, maximum: 99, example: 3),
    ],
)]
final class UpdateCartItemRequest
{
    // Documentation-only.
}
