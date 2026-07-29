<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrderInstallation',
    required: ['id', 'status', 'scheduled_for_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 88),
        new OA\Property(property: 'status', type: 'string', enum: ['scheduled', 'in_progress', 'completed', 'cancelled']),
        new OA\Property(property: 'scheduled_for_at', type: 'string', format: 'date-time', example: '2026-05-20T10:00:00+04:00'),
        new OA\Property(
            property: 'technician',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 89),
                new OA\Property(property: 'name', type: 'string', example: 'Tech Crew A'),
            ],
        ),
        new OA\Property(property: 'installation_notes', type: 'string', nullable: true, example: 'Bring spare filter cartridge'),
        new OA\Property(property: 'address_snapshot', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
final class AdminOrderInstallation
{
    // Documentation-only.
}
