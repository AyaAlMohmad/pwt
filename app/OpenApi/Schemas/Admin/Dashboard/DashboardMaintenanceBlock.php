<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardMaintenanceBlock',
    type: 'object',
    required: ['pending_requests', 'overdue', 'due_this_month'],
    properties: [
        new OA\Property(property: 'pending_requests', type: 'integer', example: 7,
            description: 'All-time open maintenance requests.'),
        new OA\Property(property: 'overdue', type: 'integer', example: 2,
            description: 'Pending requests with preferred_date in the past.'),
        new OA\Property(property: 'due_this_month', type: 'integer', example: 11,
            description: 'Machines whose next_maintenance_due_at falls this month.'),
    ],
)]
final class DashboardMaintenanceBlock
{
    // Documentation-only.
}
