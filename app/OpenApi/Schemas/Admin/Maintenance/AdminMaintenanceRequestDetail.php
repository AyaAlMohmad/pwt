<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMaintenanceRequestDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/AdminMaintenanceRequest'),
        new OA\Schema(
            type: 'object',
            properties: [
                new OA\Property(property: 'description', type: 'string'),
                new OA\Property(property: 'internal_notes', type: 'string', nullable: true),
                new OA\Property(property: 'resolution_notes', type: 'string', nullable: true),
                new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true),
                new OA\Property(
                    property: 'cancelled_by',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                    ],
                ),
                new OA\Property(
                    property: 'media',
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'url', type: 'string', format: 'uri'),
                            new OA\Property(property: 'mime', type: 'string'),
                            new OA\Property(property: 'size', type: 'integer'),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        ],
                    ),
                ),
                new OA\Property(
                    property: 'status_history',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/AdminMaintenanceStatusHistoryEntry'),
                ),
                new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
            ],
        ),
    ],
)]
final class AdminMaintenanceRequestDetail
{
    // Documentation-only.
}
