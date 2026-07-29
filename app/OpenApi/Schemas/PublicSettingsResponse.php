<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PublicSettingsResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Public settings retrieved'),
        new OA\Property(property: 'data', ref: '#/components/schemas/PublicSettings'),
    ],
    type: 'object',
)]
final class PublicSettingsResponse
{
    // Documentation-only.
}
