<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceRequestSummary',
    required: ['id', 'public_id', 'issue_type', 'status', 'priority', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 88),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid', example: '1a2b3c4d-5e6f-7081-9203-a4b5c6d7e8f9'),
        new OA\Property(property: 'issue_type', ref: '#/components/schemas/MaintenanceIssueType'),
        new OA\Property(property: 'description_excerpt', type: 'string', example: 'The water flow has been slow for the last week. Please check the…'),
        new OA\Property(property: 'status', ref: '#/components/schemas/MaintenanceStatus'),
        new OA\Property(property: 'priority', ref: '#/components/schemas/MaintenancePriority'),
        new OA\Property(property: 'preferred_date', type: 'string', format: 'date', nullable: true, example: '2026-05-20'),
        new OA\Property(property: 'preferred_time', type: 'string', nullable: true, example: '09:00 - 12:00'),
        new OA\Property(
            property: 'machine',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 423),
                new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'serial_number', type: 'string', example: 'PW50M-AE-001234'),
                new OA\Property(
                    property: 'product',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'code', type: 'string', example: 'PW50M'),
                        new OA\Property(property: 'name', type: 'string', example: 'PW50M Filtration Unit'),
                    ],
                ),
            ],
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T11:00:00+04:00'),
    ],
    type: 'object',
)]
final class MaintenanceRequestSummary
{
    // Documentation-only.
}
