<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrdersReportSummary',
    required: ['orders_count', 'total_gross', 'currency', 'by_status'],
    properties: [
        new OA\Property(property: 'orders_count', type: 'integer', example: 540),
        new OA\Property(property: 'total_gross', type: 'string', example: '612000.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(
            property: 'by_status',
            type: 'object',
            properties: [
                new OA\Property(property: 'pending', type: 'integer', example: 23),
                new OA\Property(property: 'confirmed', type: 'integer', example: 41),
                new OA\Property(property: 'scheduled', type: 'integer', example: 18),
                new OA\Property(property: 'out_for_delivery', type: 'integer', example: 5),
                new OA\Property(property: 'installed', type: 'integer', example: 102),
                new OA\Property(property: 'completed', type: 'integer', example: 339),
                new OA\Property(property: 'cancelled', type: 'integer', example: 12),
            ],
        ),
    ],
    type: 'object',
)]
final class OrdersReportSummary
{
    // Documentation-only.
}
