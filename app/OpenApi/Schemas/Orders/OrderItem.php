<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderItem',
    required: ['id', 'product_id', 'product_code', 'product_name', 'term', 'unit_price', 'quantity', 'currency', 'line_subtotal', 'line_total'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 4201),
        new OA\Property(property: 'product_id', type: 'integer', example: 5),
        new OA\Property(property: 'product_code', type: 'string', example: 'PW50M'),
        new OA\Property(property: 'product_name', type: 'string', example: 'PW50M Counter-top RO'),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc'], example: 'buy'),
        new OA\Property(property: 'unit_price', type: 'string', example: '2500.00'),
        new OA\Property(property: 'quantity', type: 'integer', example: 1),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'line_subtotal', type: 'string', example: '2500.00'),
        new OA\Property(property: 'line_discount', type: 'string', example: '250.00'),
        new OA\Property(property: 'line_tax', type: 'string', example: '112.50'),
        new OA\Property(property: 'line_total', type: 'string', example: '2362.50'),
        new OA\Property(property: 'rent_period', type: 'string', nullable: true, enum: ['monthly', 'yearly', null], example: null),
        new OA\Property(property: 'rent_min_term_months', type: 'integer', nullable: true, example: null),
    ],
    type: 'object',
)]
final class OrderItem
{
    // Documentation-only.
}
