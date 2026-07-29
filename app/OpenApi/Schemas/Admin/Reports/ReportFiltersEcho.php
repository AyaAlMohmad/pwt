<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReportFiltersEcho',
    description: 'Echo of the filters the report was generated for, including defaults injected by the service (e.g. last-30-days date range).',
    properties: [
        new OA\Property(property: 'date_from', type: 'string', format: 'date', nullable: true, example: '2026-04-16'),
        new OA\Property(property: 'date_to', type: 'string', format: 'date', nullable: true, example: '2026-05-16'),
        new OA\Property(property: 'country', type: 'string', nullable: true, example: 'AE'),
        new OA\Property(property: 'bucket', type: 'string', nullable: true, enum: ['daily', 'monthly']),
        new OA\Property(property: 'product_id', type: 'integer', nullable: true, example: null),
        new OA\Property(property: 'term', type: 'string', nullable: true, enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true, enum: ['card', 'apple_pay', 'google_pay', 'cod']),
        new OA\Property(property: 'status', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'assigned_to_user_id', type: 'integer', nullable: true, example: null),
        new OA\Property(property: 'issue_type', type: 'string', nullable: true),
        new OA\Property(property: 'priority', type: 'string', nullable: true),
        new OA\Property(property: 'has_orders', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
    ],
    type: 'object',
)]
final class ReportFiltersEcho
{
    // Documentation-only.
}
