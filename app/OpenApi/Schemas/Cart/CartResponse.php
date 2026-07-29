<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Cart;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CartResponse',
    type: 'object',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Cart retrieved.'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'currency', type: 'string', example: 'AED'),
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'product_id', type: 'integer'),
                        new OA\Property(property: 'term', type: 'string'),
                        new OA\Property(property: 'quantity', type: 'integer'),
                        new OA\Property(
                            property: 'product',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'code', type: 'string'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'image_url', type: 'string', nullable: true),
                            ],
                        ),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    ]),
                ),
                new OA\Property(property: 'item_count', type: 'integer', example: 2),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
            ],
        ),
    ],
)]
final class CartResponse
{
    // Documentation-only.
}
