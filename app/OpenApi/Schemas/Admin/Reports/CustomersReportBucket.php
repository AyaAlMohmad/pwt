<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomersReportBucket',
    required: ['bucket', 'new_customers_count'],
    properties: [
        new OA\Property(property: 'bucket', type: 'string', example: '2026-04'),
        new OA\Property(property: 'country_code', type: 'string', nullable: true, example: 'AE'),
        new OA\Property(property: 'new_customers_count', type: 'integer', example: 42),
    ],
    type: 'object',
)]
final class CustomersReportBucket
{
    // Documentation-only.
}
