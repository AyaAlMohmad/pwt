<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportTicketStatusHistoryEntry',
    required: ['id', 'to_status', 'actor_type', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 312),
        new OA\Property(property: 'from_status', type: 'string', nullable: true),
        new OA\Property(property: 'to_status', type: 'string'),
        new OA\Property(property: 'actor_type', type: 'string', enum: ['customer', 'admin', 'system']),
        new OA\Property(property: 'actor', ref: '#/components/schemas/AdminSupportActor', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminSupportTicketStatusHistoryEntry
{
    // Documentation-only.
}
