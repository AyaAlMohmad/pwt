<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMachineDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/AdminMachine'),
        new OA\Schema(
            type: 'object',
            properties: [
                new OA\Property(property: 'notes', type: 'string', nullable: true),
                new OA\Property(property: 'location_label', type: 'string', nullable: true),
                new OA\Property(property: 'latest_completed_log_at', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'total_logs_count', type: 'integer', example: 12),
                new OA\Property(
                    property: 'maintenance_logs',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/AdminMachineMaintenanceLog'),
                ),
                new OA\Property(
                    property: 'maintenance_requests',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'status', type: 'string'),
                            new OA\Property(property: 'priority', type: 'string'),
                            new OA\Property(property: 'issue_type', type: 'string'),
                            new OA\Property(
                                property: 'assigned_to',
                                type: 'object',
                                nullable: true,
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                ],
                            ),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
                        ],
                    ),
                ),
                new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(
                    property: 'order',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'status', type: 'string'),
                    ],
                ),
            ],
        ),
    ],
)]
final class AdminMachineDetail
{
    // Documentation-only.
}
