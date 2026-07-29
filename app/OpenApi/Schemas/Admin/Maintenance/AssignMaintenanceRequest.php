<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AssignMaintenanceRequest',
    required: ['assigned_to_user_id'],
    properties: [
        new OA\Property(property: 'assigned_to_user_id', type: 'integer', example: 7, description: 'Active admin in the operations role.'),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 500, nullable: true),
    ],
    type: 'object',
)]
final class AssignMaintenanceRequest
{
    // Documentation-only.
}
