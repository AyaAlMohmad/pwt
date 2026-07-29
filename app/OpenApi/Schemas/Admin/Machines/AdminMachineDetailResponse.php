<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMachineDetailResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Machine retrieved.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminMachineDetail'),
    ],
    type: 'object',
)]
final class AdminMachineDetailResponse
{
    // Documentation-only.
}
