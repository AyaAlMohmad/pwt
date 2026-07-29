<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardRevenueBlock',
    type: 'object',
    required: ['current_period', 'previous_period', 'currency', 'trend_percent'],
    properties: [
        new OA\Property(property: 'current_period', type: 'string', example: '47200.00',
            description: 'Revenue within the selected period.'),
        new OA\Property(property: 'previous_period', type: 'string', example: '42100.00',
            description: 'Revenue within the previous matching period.'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'trend_percent', type: 'number', format: 'float', example: 12.1,
            description: 'Percentage change vs previous period.'),
    ],
)]
final class DashboardRevenueBlock
{
    // Documentation-only.
}
