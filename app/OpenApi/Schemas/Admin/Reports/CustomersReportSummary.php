<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomersReportSummary',
    required: ['customers_count', 'active_count', 'with_orders_count', 'with_machines_count'],
    properties: [
        new OA\Property(property: 'customers_count', type: 'integer', example: 1247),
        new OA\Property(property: 'active_count', type: 'integer', example: 1198),
        new OA\Property(property: 'with_orders_count', type: 'integer', example: 612),
        new OA\Property(property: 'with_machines_count', type: 'integer', example: 227),
    ],
    type: 'object',
)]
final class CustomersReportSummary
{
    // Documentation-only.
}
