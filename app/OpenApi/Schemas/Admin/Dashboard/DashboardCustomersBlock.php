<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardCustomersBlock',
    type: 'object',
    required: ['new_current_period', 'new_previous_period', 'trend', 'total_active'],
    properties: [
        new OA\Property(property: 'new_current_period', type: 'integer', example: 6,
            description: 'New customers in the selected period.'),
        new OA\Property(property: 'new_previous_period', type: 'integer', example: 4),
        new OA\Property(property: 'trend', type: 'integer', example: 2,
            description: 'Difference between current and previous period.'),
        new OA\Property(property: 'total_active', type: 'integer', example: 1198,
            description: 'All-time active customers.'),
    ],
)]
final class DashboardCustomersBlock
{
    // Documentation-only.
}
