<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MachineDetailResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Machine retrieved.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/MachineDetail'),
    ],
    type: 'object',
)]
final class MachineDetailResponse
{
    // Documentation-only.
}
