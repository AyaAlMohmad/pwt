<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Addresses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AddressDeleteResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Address deleted'),
        new OA\Property(
            property: 'data',
            required: ['id', 'deleted_at'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 17),
                new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', example: '2026-05-15T10:00:00+04:00'),
                new OA\Property(property: 'promoted_default_id', type: 'integer', nullable: true, example: 12, description: 'Id of the address auto-promoted to default when the deleted row was the previous default; null otherwise.'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class AddressDeleteResponse
{
    // Documentation-only.
}
