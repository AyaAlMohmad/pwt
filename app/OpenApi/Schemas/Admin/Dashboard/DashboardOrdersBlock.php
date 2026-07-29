<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardOrdersBlock',
    type: 'object',
    required: ['status_counts', 'current_period', 'previous_period', 'trend', 'total_this_month'],
    properties: [
        new OA\Property(property: 'status_counts', type: 'object',
            description: 'All-time counts keyed by OrderStatus value.',
            example: ['pending' => 23, 'confirmed' => 41, 'scheduled' => 18, 'out_for_delivery' => 5, 'installed' => 102, 'completed' => 339, 'cancelled' => 12]),
        new OA\Property(property: 'current_period', type: 'integer', example: 14,
            description: 'Orders created within the selected period.'),
        new OA\Property(property: 'previous_period', type: 'integer', example: 11,
            description: 'Orders created within the previous matching period.'),
        new OA\Property(property: 'trend', type: 'integer', example: 3,
            description: 'Difference between current and previous period.'),
        new OA\Property(property: 'total_this_month', type: 'integer', example: 247),
    ],
)]
final class DashboardOrdersBlock
{
    // Documentation-only.
}
