<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardContactLeadsBlock',
    type: 'object',
    required: ['open_count', 'new_current_period', 'new_previous_period', 'trend'],
    properties: [
        new OA\Property(property: 'open_count', type: 'integer', example: 4,
            description: 'Unprocessed contact leads awaiting review.'),
        new OA\Property(property: 'new_current_period', type: 'integer', example: 12),
        new OA\Property(property: 'new_previous_period', type: 'integer', example: 8),
        new OA\Property(property: 'trend', type: 'integer', example: 4,
            description: 'Difference between current and previous period.'),
    ],
)]
final class DashboardContactLeadsBlock
{
    // Documentation-only.
}
