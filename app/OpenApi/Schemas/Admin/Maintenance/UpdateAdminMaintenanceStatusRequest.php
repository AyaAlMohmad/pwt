<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateAdminMaintenanceStatusRequest',
    required: ['status'],
    description: '`assigned` -> use PATCH /assign; `completed` -> use POST /close (both return 409 here).',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['in_progress', 'cancelled']),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 500, nullable: true),
        new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000, nullable: true, description: 'Required when status=cancelled.'),
    ],
    type: 'object',
)]
final class UpdateAdminMaintenanceStatusRequest
{
    // Documentation-only.
}
