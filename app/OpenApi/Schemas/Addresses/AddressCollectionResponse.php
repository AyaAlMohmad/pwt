<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Addresses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AddressCollectionResponse',
    description: 'Unpaginated list of the caller\'s addresses, sorted by is_default DESC then created_at DESC (max 10 per customer).',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Addresses retrieved'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Address'),
        ),
    ],
    type: 'object',
)]
final class AddressCollectionResponse
{
    // Documentation-only.
}
