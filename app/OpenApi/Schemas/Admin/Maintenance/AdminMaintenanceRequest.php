<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMaintenanceRequest',
    required: ['id', 'public_id', 'issue_type', 'status', 'priority', 'created_at', 'is_trashed'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 88),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'issue_type', type: 'string', example: 'filter_change'),
        new OA\Property(property: 'description_excerpt', type: 'string', example: 'The water flow has been slow for the last week…'),
        new OA\Property(property: 'status', type: 'string', enum: ['new', 'assigned', 'in_progress', 'completed', 'cancelled']),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'preferred_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(
            property: 'customer',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 412),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'machine',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'serial_number', type: 'string'),
                new OA\Property(
                    property: 'product',
                    type: 'object',
                    nullable: true,
                    properties: [new OA\Property(property: 'code', type: 'string')],
                ),
            ],
        ),
        new OA\Property(property: 'assigned_to', ref: '#/components/schemas/AdminMaintenanceActor', nullable: true),
        new OA\Property(property: 'country_code', type: 'string', nullable: true, example: 'ae'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_trashed', type: 'boolean', example: false),
    ],
    type: 'object',
)]
final class AdminMaintenanceRequest
{
    // Documentation-only.
}
