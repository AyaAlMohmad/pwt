<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrdersReportRow',
    required: ['order_id', 'order_public_id', 'placed_at', 'status', 'currency', 'total_amount'],
    properties: [
        new OA\Property(property: 'order_id', type: 'integer', example: 1057),
        new OA\Property(property: 'order_public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'placed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod']),
        new OA\Property(property: 'customer_name', type: 'string', nullable: true),
        new OA\Property(property: 'customer_email', type: 'string', nullable: true),
        new OA\Property(property: 'country_code', type: 'string', nullable: true, example: 'AE'),
        new OA\Property(property: 'total_amount', type: 'string', example: '2415.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
    ],
    type: 'object',
)]
final class OrdersReportRow
{
    // Documentation-only.
}
