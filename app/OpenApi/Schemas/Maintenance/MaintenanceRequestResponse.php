<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceRequestResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Maintenance request submitted.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/MaintenanceRequestDetail'),
    ],
    type: 'object',
)]
final class MaintenanceRequestResponse
{
    // Documentation-only.
}
