<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Reports;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceReportRow',
    required: ['id', 'public_id', 'created_at', 'status', 'priority', 'issue_type'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 312),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string', enum: ['new', 'assigned', 'in_progress', 'completed', 'cancelled']),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'issue_type', type: 'string'),
        new OA\Property(
            property: 'customer',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', nullable: true),
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', nullable: true),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'machine',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', nullable: true),
                new OA\Property(property: 'serial_number', type: 'string', nullable: true),
                new OA\Property(property: 'product_code', type: 'string', nullable: true),
                new OA\Property(property: 'country_code', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'assigned_to',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'resolution_hours', type: 'number', format: 'float', nullable: true),
    ],
    type: 'object',
)]
final class MaintenanceReportRow
{
    // Documentation-only.
}
