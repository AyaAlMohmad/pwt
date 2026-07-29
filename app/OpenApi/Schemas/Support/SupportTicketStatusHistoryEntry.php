<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketStatusHistoryEntry',
    required: ['to_status', 'actor_type', 'created_at'],
    properties: [
        new OA\Property(property: 'from_status', type: 'string', nullable: true, example: 'open'),
        new OA\Property(property: 'to_status', type: 'string', example: 'cancelled'),
        new OA\Property(property: 'actor_type', type: 'string',
            enum: ['customer', 'admin', 'system']),
        new OA\Property(property: 'note', type: 'string', nullable: true,
            description: 'Stores cancellation_reason for customer cancels and admin notes for admin actions.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time',
            example: '2026-05-15T13:30:00+04:00'),
    ],
    type: 'object',
)]
final class SupportTicketStatusHistoryEntry
{
    // Documentation-only.
}
