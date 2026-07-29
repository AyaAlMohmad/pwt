<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MachineDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/MachineSummary'),
        new OA\Schema(
            properties: [
                new OA\Property(property: 'filter_change_interval_months', type: 'integer', nullable: true, example: 3),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
                new OA\Property(property: 'latest_completed_log_at', type: 'string', format: 'date', nullable: true, example: '2026-03-12'),
                new OA\Property(property: 'total_logs_count', type: 'integer', example: 9),
                new OA\Property(property: 'open_requests_count', type: 'integer', example: 1),
                new OA\Property(
                    property: 'maintenance_logs',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/MachineMaintenanceLog'),
                    description: 'Latest 50 logs ordered by scheduled_at DESC. Older entries are truncated; total_logs_count exposes the full count.',
                ),
            ],
            type: 'object',
        ),
    ],
)]
final class MachineDetail
{
    // Documentation-only.
}
