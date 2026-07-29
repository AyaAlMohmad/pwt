<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceStatusHistoryEntry',
    required: ['to_status', 'actor_type', 'created_at'],
    properties: [
        new OA\Property(property: 'from_status', type: 'string', nullable: true, example: 'new'),
        new OA\Property(property: 'to_status', type: 'string', example: 'assigned'),
        new OA\Property(property: 'actor_type', type: 'string', enum: ['customer', 'admin', 'system']),
        new OA\Property(property: 'actor', ref: '#/components/schemas/MaintenanceActorSummary', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T13:30:00+04:00'),
    ],
    type: 'object',
)]
final class MaintenanceStatusHistoryEntry
{
    // Documentation-only.
}
