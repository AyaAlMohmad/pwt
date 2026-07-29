<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomersReportRow',
    required: ['id', 'is_active', 'created_at', 'order_count', 'total_spend', 'machines_count'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 412),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'country_code', type: 'string', nullable: true, example: 'AE'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'order_count', type: 'integer', example: 4),
        new OA\Property(property: 'total_spend', type: 'string', example: '8430.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'last_order_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'machines_count', type: 'integer', example: 2),
    ],
    type: 'object',
)]
final class CustomersReportRow
{
    // Documentation-only.
}
