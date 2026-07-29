<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMaintenanceActor',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Hassan Khalil'),
        new OA\Property(property: 'role', type: 'string', nullable: true, example: 'operations'),
    ],
    type: 'object',
)]
final class AdminMaintenanceActor
{
    // Documentation-only.
}
