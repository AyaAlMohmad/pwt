<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateOrderRequest',
    required: ['items', 'delivery_date', 'delivery_time_slot', 'term', 'payment_method'],
    properties: [
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/CreateOrderItemInput'),
            minItems: 1,
            maxItems: 10,
        ),
        new OA\Property(property: 'delivery_address_id', type: 'integer', nullable: true, example: 17),
        new OA\Property(property: 'delivery_address_line_1', type: 'string', nullable: true, maxLength: 255, example: 'Building 5, Floor 3'),
        new OA\Property(property: 'delivery_date', type: 'string', format: 'date', example: '2026-06-20'),
        new OA\Property(property: 'delivery_time_slot', type: 'string', enum: ['morning', 'afternoon', 'evening'], example: 'morning'),
        new OA\Property(property: 'billing_address_id', type: 'integer', nullable: true, example: null),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc'], example: 'buy'),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod'], example: 'card'),
        new OA\Property(property: 'promo_code', type: 'string', nullable: true, maxLength: 40, example: 'WELCOME10'),
        new OA\Property(property: 'notes', type: 'string', nullable: true, maxLength: 500, example: 'Please call before delivery.'),
    ],
    type: 'object',
)]
final class CreateOrderRequest
{
    // Documentation-only.
}
