<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\User;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MeResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(property: 'data', ref: '#/components/schemas/User'),
    ],
    type: 'object',
)]
final class MeResponse
{
    // Documentation-only.
}
