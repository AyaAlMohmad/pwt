<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceReportSummary',
    required: ['requests_count', 'by_status', 'by_priority'],
    properties: [
        new OA\Property(property: 'requests_count', type: 'integer', example: 84),
        new OA\Property(property: 'by_status', type: 'object',
            properties: [
                new OA\Property(property: 'new', type: 'integer', example: 12),
                new OA\Property(property: 'assigned', type: 'integer', example: 8),
                new OA\Property(property: 'in_progress', type: 'integer', example: 5),
                new OA\Property(property: 'completed', type: 'integer', example: 56),
                new OA\Property(property: 'cancelled', type: 'integer', example: 3),
            ],
        ),
        new OA\Property(property: 'by_priority', type: 'object',
            properties: [
                new OA\Property(property: 'low', type: 'integer', example: 10),
                new OA\Property(property: 'medium', type: 'integer', example: 51),
                new OA\Property(property: 'high', type: 'integer', example: 20),
                new OA\Property(property: 'urgent', type: 'integer', example: 3),
            ],
        ),
        new OA\Property(property: 'average_resolution_hours', type: 'number', format: 'float', nullable: true, example: 47.5),
    ],
    type: 'object',
)]
final class MaintenanceReportSummary
{
    // Documentation-only.
}
