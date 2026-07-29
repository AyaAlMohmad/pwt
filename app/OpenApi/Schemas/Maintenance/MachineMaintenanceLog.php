<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MachineMaintenanceLog',
    required: ['id', 'status', 'scheduled_at', 'source'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 991),
        new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'completed', 'missed', 'cancelled']),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date', example: '2026-03-12'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date', nullable: true, example: '2026-03-12'),
        new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Filter cartridge replaced.'),
        new OA\Property(property: 'source', type: 'string', enum: ['imported', 'manual', 'request']),
        new OA\Property(property: 'maintenance_request_id', type: 'integer', nullable: true, example: null),
    ],
    type: 'object',
)]
final class MachineMaintenanceLog
{
    // Documentation-only.
}
