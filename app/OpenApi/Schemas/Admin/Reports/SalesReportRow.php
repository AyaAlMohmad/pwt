<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SalesReportRow',
    required: ['order_id', 'order_public_id', 'placed_at', 'status', 'term', 'payment_method', 'currency', 'total_amount'],
    properties: [
        new OA\Property(property: 'order_id', type: 'integer', example: 1057),
        new OA\Property(property: 'order_public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'placed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'scheduled', 'out_for_delivery', 'installed', 'completed', 'cancelled']),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod']),
        new OA\Property(
            property: 'customer',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', nullable: true),
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', nullable: true),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'country_code', type: 'string', nullable: true, example: 'AE'),
        new OA\Property(property: 'subtotal_amount', type: 'string', example: '2400.00'),
        new OA\Property(property: 'discount_amount', type: 'string', example: '100.00'),
        new OA\Property(property: 'tax_amount', type: 'string', example: '115.00'),
        new OA\Property(property: 'shipping_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'total_amount', type: 'string', example: '2415.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
    ],
    type: 'object',
)]
final class SalesReportRow
{
    // Documentation-only.
}
