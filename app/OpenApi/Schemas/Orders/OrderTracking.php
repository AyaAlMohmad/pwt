<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderTracking',
    required: ['order_id', 'public_id', 'status', 'tracking_progress', 'timeline'],
    properties: [
        new OA\Property(property: 'order_id', type: 'integer', example: 1057),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'scheduled', 'out_for_delivery', 'installed', 'completed', 'cancelled']),
        new OA\Property(property: 'tracking_progress', type: 'integer', minimum: 0, maximum: 100, example: 50),
        new OA\Property(
            property: 'next_step',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'out_for_delivery'),
                new OA\Property(property: 'label', type: 'string', example: 'Awaiting dispatch'),
                new OA\Property(property: 'estimated_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'action_required', type: 'string', enum: ['none', 'customer_complete_payment', 'customer_be_available']),
            ],
        ),
        new OA\Property(
            property: 'estimated_delivery_window',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'from', type: 'string', format: 'date-time'),
                new OA\Property(property: 'to', type: 'string', format: 'date-time'),
            ],
        ),
        new OA\Property(
            property: 'timeline',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/OrderStatusHistoryEntry'),
        ),
    ],
    type: 'object',
)]
final class OrderTracking
{
    // Documentation-only.
}
