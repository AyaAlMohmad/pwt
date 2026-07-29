<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductDetailResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Product retrieved'),
        new OA\Property(property: 'data', ref: '#/components/schemas/ProductDetail'),
    ],
    type: 'object',
)]
final class ProductDetailResponse
{
    // Documentation-only.
}
