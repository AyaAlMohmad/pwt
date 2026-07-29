<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreMaintenanceRequestRequest',
    required: ['machine_id', 'issue_type', 'description'],
    properties: [
        new OA\Property(property: 'machine_id', type: 'integer', example: 423,
            description: 'Must be an active machine owned by the caller.'),
        new OA\Property(property: 'issue_type', ref: '#/components/schemas/MaintenanceIssueType'),
        new OA\Property(property: 'description', type: 'string', minLength: 10, maxLength: 2000,
            example: 'The water flow has been slow for the last week. Please check the cartridges.'),
        new OA\Property(property: 'preferred_date', type: 'string', format: 'date', nullable: true,
            example: '2026-05-20',
            description: 'Optional. Must be today or in the future and within 90 days.'),
        new OA\Property(property: 'preferred_time', type: 'string', nullable: true, maxLength: 16,
            example: '09:00 - 12:00',
            description: 'Optional free-text preferred time window.'),
        new OA\Property(property: 'priority', type: 'string', nullable: true,
            enum: ['low', 'medium'],
            description: 'Optional. Server silently coerces high/urgent down to medium.'),
        new OA\Property(
            property: 'media_file_ids',
            type: 'array',
            nullable: true,
            maxItems: 5,
            items: new OA\Items(type: 'integer'),
            description: 'Up to 5 file ids (must be type=maintenance_media, owned by caller, not yet attached).',
        ),
    ],
    type: 'object',
)]
final class StoreMaintenanceRequestRequest
{
    // Documentation-only.
}
