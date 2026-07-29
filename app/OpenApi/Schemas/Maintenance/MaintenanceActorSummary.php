<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceActorSummary',
    required: ['first_name'],
    properties: [
        new OA\Property(property: 'first_name', type: 'string', example: 'Hassan'),
    ],
    type: 'object',
    description: 'Privacy-anonymised actor — only the first name is exposed to the customer.',
)]
final class MaintenanceActorSummary
{
    // Documentation-only.
}
