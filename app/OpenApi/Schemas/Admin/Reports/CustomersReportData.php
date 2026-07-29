<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomersReportData',
    required: ['filters', 'summary', 'buckets', 'rows'],
    properties: [
        new OA\Property(property: 'filters', ref: '#/components/schemas/ReportFiltersEcho'),
        new OA\Property(property: 'summary', ref: '#/components/schemas/CustomersReportSummary'),
        new OA\Property(property: 'buckets', type: 'array',
            items: new OA\Items(ref: '#/components/schemas/CustomersReportBucket')),
        new OA\Property(property: 'rows', type: 'array',
            items: new OA\Items(ref: '#/components/schemas/CustomersReportRow')),
    ],
    type: 'object',
)]
final class CustomersReportData
{
    // Documentation-only.
}
