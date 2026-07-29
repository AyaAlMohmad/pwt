<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceReportBucket',
    required: ['bucket', 'requests_count', 'completed_count'],
    properties: [
        new OA\Property(property: 'bucket', type: 'string', example: '2026-04'),
        new OA\Property(property: 'requests_count', type: 'integer', example: 30),
        new OA\Property(property: 'completed_count', type: 'integer', example: 22),
    ],
    type: 'object',
)]
final class MaintenanceReportBucket
{
    // Documentation-only.
}
