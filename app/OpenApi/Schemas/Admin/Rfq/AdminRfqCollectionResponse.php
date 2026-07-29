<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Rfq;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminRfqCollectionResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AdminRfqResponse'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                new OA\Property(property: 'total', type: 'integer', example: 42),
            ],
        ),
    ],
)]
final class AdminRfqCollectionResponse
{
    // Documentation-only.
}
