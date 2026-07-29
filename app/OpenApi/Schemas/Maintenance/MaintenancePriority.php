<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenancePriority',
    type: 'string',
    enum: ['low', 'medium', 'high', 'urgent'],
    description: 'Priority of a maintenance request. Customers are silently capped at medium server-side.',
)]
final class MaintenancePriority
{
    // Documentation-only.
}
