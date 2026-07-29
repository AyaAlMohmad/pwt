<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductCollectionResponse',
    required: ['success', 'message', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Products retrieved'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Product'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            required: ['pagination'],
            properties: [
                new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
            ],
        ),
    ],
    type: 'object',
)]
final class ProductCollectionResponse
{
    // Documentation-only.
}
