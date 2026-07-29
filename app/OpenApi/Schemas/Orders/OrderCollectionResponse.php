<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderCollectionResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Orders retrieved'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Order'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
            ],
        ),
    ],
    type: 'object',
)]
final class OrderCollectionResponse
{
    // Documentation-only.
}
