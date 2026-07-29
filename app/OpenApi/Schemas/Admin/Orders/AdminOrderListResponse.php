<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrderListResponse',
    required: ['success', 'message', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Orders retrieved'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'counts',
                    type: 'object',
                    description: 'Order count grouped by status',
                    example: ['pending' => 15, 'confirmed' => 8, 'scheduled' => 0],
                ),
                new OA\Property(
                    property: 'orders',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/AdminOrder'),
                ),
            ],
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
final class AdminOrderListResponse
{
    // Documentation-only.
}
