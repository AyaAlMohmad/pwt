<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMachineMaintenanceLog',
    required: ['id', 'status', 'scheduled_at', 'source'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 9123),
        new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'completed', 'missed', 'cancelled']),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'source', type: 'string', enum: ['imported', 'manual', 'request']),
        new OA\Property(property: 'maintenance_request_id', type: 'integer', nullable: true),
        new OA\Property(
            property: 'technician',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
        new OA\Property(
            property: 'created_by',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
    ],
    type: 'object',
)]
final class AdminMachineMaintenanceLog
{
    // Documentation-only.
}
