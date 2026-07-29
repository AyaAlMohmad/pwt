<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SalesReportSummary',
    required: ['orders_count', 'currency', 'subtotal_gross', 'discount_total', 'tax_total', 'shipping_total', 'net_revenue', 'average_order_value'],
    properties: [
        new OA\Property(property: 'orders_count', type: 'integer', example: 312),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'subtotal_gross', type: 'string', example: '412500.00'),
        new OA\Property(property: 'discount_total', type: 'string', example: '18750.50'),
        new OA\Property(property: 'tax_total', type: 'string', example: '20687.48'),
        new OA\Property(property: 'shipping_total', type: 'string', example: '0.00'),
        new OA\Property(property: 'net_revenue', type: 'string', example: '414437.98'),
        new OA\Property(property: 'average_order_value', type: 'string', example: '1328.32'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ],
    type: 'object',
)]
final class SalesReportSummary
{
    // Documentation-only.
}
