<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateAdminMaintenanceStatusRequest',
    required: ['status'],
    description: '`status` accepts the enum name or its numeric code: 1=new 2=assigned 3=in_progress 4=completed 5=cancelled. `assigned` -> use PATCH /assign; `completed` -> use POST /close (both return 409 here).',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['in_progress', 'cancelled'], description: 'Status name or numeric code (3=in_progress, 5=cancelled).'),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 500, nullable: true),
        new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000, nullable: true, description: 'Required when status=cancelled.'),
    ],
    type: 'object',
)]
final class UpdateAdminMaintenanceStatusRequest
{
    // Documentation-only.
}
