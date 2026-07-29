<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CloseMaintenanceRequest',
    required: ['resolution_notes'],
    properties: [
        new OA\Property(property: 'resolution_notes', type: 'string', minLength: 5, maxLength: 2000),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date', nullable: true, description: 'Defaults to today; must be on/before today and on/after the machine installed_at and request created_at.'),
    ],
    type: 'object',
)]
final class CloseMaintenanceRequest
{
    // Documentation-only.
}
