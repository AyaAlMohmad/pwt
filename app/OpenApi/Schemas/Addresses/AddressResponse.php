<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Addresses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AddressResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Address created'),
        new OA\Property(property: 'data', ref: '#/components/schemas/Address'),
    ],
    type: 'object',
)]
final class AddressResponse
{
    // Documentation-only.
}
