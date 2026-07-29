<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderStatusHistoryEntry',
    required: ['to_status', 'actor_type', 'occurred_at'],
    properties: [
        new OA\Property(property: 'from_status', type: 'string', nullable: true, example: 'pending'),
        new OA\Property(property: 'to_status', type: 'string', example: 'confirmed'),
        new OA\Property(property: 'actor_type', type: 'string', enum: ['customer', 'admin', 'system'], example: 'system'),
        new OA\Property(property: 'note', type: 'string', nullable: true, example: 'Payment webhook PAYMENT_SUCCEEDED'),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', example: '2026-05-15T11:01:00+04:00'),
    ],
    type: 'object',
)]
final class OrderStatusHistoryEntry
{
    // Documentation-only.
}
