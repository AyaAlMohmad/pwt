<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Region',
    required: ['name', 'is_active'],
    properties: [
        new OA\Property(property: 'code', type: 'string', nullable: true, example: 'dxb'),
        new OA\Property(property: 'name', type: 'string', example: 'Dubai'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ],
    type: 'object',
)]
final class Region
{
    // Documentation-only.
}
