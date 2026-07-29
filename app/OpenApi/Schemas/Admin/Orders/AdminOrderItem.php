<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrderItem',
    required: ['id', 'product_id', 'product_code', 'product_name', 'term', 'quantity', 'currency', 'line_total'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 18991),
        new OA\Property(property: 'product_id', type: 'integer', example: 7),
        new OA\Property(property: 'product_code', type: 'string', example: 'PW50M'),
        new OA\Property(property: 'product_name', type: 'string', example: 'PW50M Water Filter'),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'unit_price', type: 'string', example: '2250.00'),
        new OA\Property(property: 'quantity', type: 'integer', example: 1),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'line_subtotal', type: 'string', example: '2250.00'),
        new OA\Property(property: 'line_discount', type: 'string', example: '0.00'),
        new OA\Property(property: 'line_tax', type: 'string', example: '112.50'),
        new OA\Property(property: 'line_total', type: 'string', example: '2362.50'),
        new OA\Property(property: 'rent_period', type: 'string', nullable: true, enum: ['monthly', 'yearly']),
        new OA\Property(property: 'rent_min_term_months', type: 'integer', nullable: true),
    ],
    type: 'object',
)]
final class AdminOrderItem
{
    // Documentation-only.
}
