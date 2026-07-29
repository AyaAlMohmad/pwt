<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductSpec',
    required: ['id', 'label', 'value', 'display_order'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 71),
        new OA\Property(property: 'label', type: 'string', example: 'Filtration stages'),
        new OA\Property(property: 'value', type: 'string', example: '5'),
        new OA\Property(property: 'display_order', type: 'integer', example: 0),
    ],
    type: 'object',
)]
final class ProductSpec
{
    // Documentation-only.
}
