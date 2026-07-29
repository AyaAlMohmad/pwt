<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceStatus',
    type: 'string',
    enum: ['new', 'assigned', 'in_progress', 'completed', 'cancelled'],
    description: 'Lifecycle status of a maintenance request. Terminal states: completed, cancelled.',
)]
final class MaintenanceStatus
{
    // Documentation-only.
}
