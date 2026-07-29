<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Order'),
        new OA\Schema(
            properties: [
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/OrderItem'),
                ),
                new OA\Property(property: 'delivery_address', ref: '#/components/schemas/Address', nullable: true),
                new OA\Property(property: 'billing_address', ref: '#/components/schemas/Address', nullable: true),
                new OA\Property(
                    property: 'status_history',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/OrderStatusHistoryEntry'),
                ),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
                new OA\Property(
                    property: 'payment',
                    type: 'object',
                    nullable: true,
                    description: 'Phase 9 — null in Phase 8 (no payments table yet).',
                ),
            ],
            type: 'object',
        ),
    ],
)]
final class OrderDetail
{
    // Documentation-only.
}
