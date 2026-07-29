<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMaintenanceDetailResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Maintenance request retrieved.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminMaintenanceRequestDetail'),
    ],
    type: 'object',
)]
final class AdminMaintenanceDetailResponse
{
    // Documentation-only.
}
