<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMachine',
    required: ['id', 'public_id', 'serial_number', 'term', 'installed_at', 'country_code', 'source', 'is_active', 'is_trashed'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 423),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'serial_number', type: 'string', example: 'PW50M-AE-001234'),
        new OA\Property(
            property: 'product',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 12),
                new OA\Property(property: 'code', type: 'string', example: 'PW50M'),
            ],
        ),
        new OA\Property(property: 'customer', ref: '#/components/schemas/AdminMachineCustomer', nullable: true),
        new OA\Property(
            property: 'address',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 17),
                new OA\Property(property: 'label', type: 'string', nullable: true, example: 'Main kitchen'),
                new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Dubai'),
                new OA\Property(
                    property: 'country',
                    type: 'object',
                    nullable: true,
                    properties: [new OA\Property(property: 'code', type: 'string', example: 'AE')],
                ),
            ],
        ),
        new OA\Property(property: 'country_code', type: 'string', example: 'ae'),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date'),
        new OA\Property(property: 'next_maintenance_due_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'filter_change_interval_months', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'source', type: 'string', enum: ['imported', 'created']),
        new OA\Property(property: 'order_id', type: 'integer', nullable: true),
        new OA\Property(property: 'needs_maintenance', type: 'boolean', example: false),
        new OA\Property(property: 'open_requests_count', type: 'integer', example: 0),
        new OA\Property(property: 'is_trashed', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminMachine
{
    // Documentation-only.
}
