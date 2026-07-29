<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SalesReportBucket',
    required: ['bucket', 'orders_count', 'subtotal_gross', 'discount_total', 'tax_total', 'net_revenue'],
    properties: [
        new OA\Property(property: 'bucket', type: 'string', example: '2026-04-16'),
        new OA\Property(property: 'orders_count', type: 'integer', example: 8),
        new OA\Property(property: 'subtotal_gross', type: 'string', example: '9600.00'),
        new OA\Property(property: 'discount_total', type: 'string', example: '0.00'),
        new OA\Property(property: 'tax_total', type: 'string', example: '480.00'),
        new OA\Property(property: 'net_revenue', type: 'string', example: '10080.00'),
    ],
    type: 'object',
)]
final class SalesReportBucket
{
    // Documentation-only.
}
