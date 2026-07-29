<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardMachinesBlock',
    type: 'object',
    required: ['total_active', 'new_current_period', 'new_previous_period', 'trend', 'customers_count', 'due_maintenance_30d'],
    properties: [
        new OA\Property(property: 'total_active', type: 'integer', example: 183,
            description: 'All-time active machines.'),
        new OA\Property(property: 'new_current_period', type: 'integer', example: 5),
        new OA\Property(property: 'new_previous_period', type: 'integer', example: 3),
        new OA\Property(property: 'trend', type: 'integer', example: 2,
            description: 'Difference between current and previous period.'),
        new OA\Property(property: 'customers_count', type: 'integer', example: 94,
            description: 'Distinct customers who own active machines.'),
        new OA\Property(property: 'due_maintenance_30d', type: 'integer', example: 11,
            description: 'Machines due maintenance within next 30 days.'),
    ],
)]
final class DashboardMachinesBlock
{
    // Documentation-only.
}
