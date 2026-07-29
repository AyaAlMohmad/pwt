<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrdersReportBucket',
    required: ['bucket', 'orders_count', 'total_gross'],
    properties: [
        new OA\Property(property: 'bucket', type: 'string', example: '2026-04'),
        new OA\Property(property: 'orders_count', type: 'integer', example: 215),
        new OA\Property(property: 'total_gross', type: 'string', example: '242500.00'),
    ],
    type: 'object',
)]
final class OrdersReportBucket
{
    // Documentation-only.
}
