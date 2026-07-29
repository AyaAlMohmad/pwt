<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrderStatusHistoryEntry',
    required: ['id', 'to_status', 'actor_type', 'is_internal', 'is_notes_only', 'occurred_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 9382),
        new OA\Property(property: 'from_status', type: 'string', nullable: true, example: 'confirmed'),
        new OA\Property(property: 'to_status', type: 'string', example: 'scheduled'),
        new OA\Property(property: 'actor_type', type: 'string', enum: ['customer', 'admin', 'system']),
        new OA\Property(property: 'actor_id', type: 'integer', nullable: true, example: 42),
        new OA\Property(property: 'actor_name', type: 'string', nullable: true, example: 'Ali Rashid'),
        new OA\Property(property: 'note', type: 'string', nullable: true, example: 'COD payment collected on doorstep'),
        new OA\Property(property: 'is_internal', type: 'boolean', example: false),
        new OA\Property(property: 'is_notes_only', type: 'boolean', example: false),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', example: '2026-05-15T11:30:00+04:00'),
    ],
    type: 'object',
)]
final class AdminOrderStatusHistoryEntry
{
    // Documentation-only.
}
