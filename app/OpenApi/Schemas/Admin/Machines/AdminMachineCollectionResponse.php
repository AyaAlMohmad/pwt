<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMachineCollectionResponse',
    required: ['success', 'message', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Machines retrieved.'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AdminMachine'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
            ],
        ),
    ],
    type: 'object',
)]
final class AdminMachineCollectionResponse
{
    // Documentation-only.
}
