<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Order placed'),
        new OA\Property(property: 'data', ref: '#/components/schemas/Order'),
    ],
    type: 'object',
)]
final class OrderResponse
{
    // Documentation-only.
}
