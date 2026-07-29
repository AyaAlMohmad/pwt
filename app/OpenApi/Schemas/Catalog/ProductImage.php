<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Catalog;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductImage',
    required: ['id', 'url', 'is_primary', 'display_order'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 41),
        new OA\Property(property: 'url', type: 'string', example: 'https://api.pwt.local/storage/products/pw50m/primary.webp'),
        new OA\Property(property: 'alt_text', type: 'string', nullable: true, example: 'PW50M front view'),
        new OA\Property(property: 'is_primary', type: 'boolean', example: true),
        new OA\Property(property: 'display_order', type: 'integer', example: 0),
    ],
    type: 'object',
)]
final class ProductImage
{
    // Documentation-only.
}
