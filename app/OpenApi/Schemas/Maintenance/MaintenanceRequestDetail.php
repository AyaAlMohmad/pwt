<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceRequestDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/MaintenanceRequestSummary'),
        new OA\Schema(
            properties: [
                new OA\Property(property: 'description', type: 'string',
                    example: 'The water flow has been slow for the last week. Please check the cartridges.'),
                new OA\Property(property: 'assigned_to', ref: '#/components/schemas/MaintenanceActorSummary', nullable: true),
                new OA\Property(
                    property: 'media',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/MaintenanceRequestMedia'),
                ),
                new OA\Property(
                    property: 'status_history',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/MaintenanceStatusHistoryEntry'),
                ),
                new OA\Property(property: 'resolution_notes', type: 'string', nullable: true,
                    description: 'Only returned when status is completed.'),
                new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true,
                    description: 'Only returned when status is cancelled.'),
                new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
            ],
            type: 'object',
        ),
    ],
)]
final class MaintenanceRequestDetail
{
    // Documentation-only.
}
