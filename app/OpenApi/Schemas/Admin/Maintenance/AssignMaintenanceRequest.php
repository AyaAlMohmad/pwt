<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AssignMaintenanceRequest',
    properties: [
        new OA\Property(property: 'assigned_to_user_id', type: 'integer', example: 7, nullable: true, description: 'Active admin in the operations role. Omit or send null to unassign — an `assigned` request returns to `new`.'),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 500, nullable: true),
    ],
    type: 'object',
)]
final class AssignMaintenanceRequest
{
    // Documentation-only.
}
