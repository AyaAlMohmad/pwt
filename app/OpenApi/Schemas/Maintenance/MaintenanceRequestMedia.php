<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceRequestMedia',
    required: ['id', 'uuid', 'url', 'mime', 'size', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', example: '0d3a6f0c-2bf1-4f8b-9a9b-3c8e1f1c2d3e'),
        new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'http://127.0.0.1:8000/api/v1/files/0d3a6f0c-2bf1-4f8b-9a9b-3c8e1f1c2d3e/download?expires=…&signature=…'),
        new OA\Property(property: 'mime', type: 'string', example: 'image/jpeg'),
        new OA\Property(property: 'size', type: 'integer', example: 482512, description: 'Size in bytes.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T10:58:00+04:00'),
    ],
    type: 'object',
    description: 'Trimmed shape of a maintenance_media file. The URL is a 10-minute signed link.',
)]
final class MaintenanceRequestMedia
{
    // Documentation-only.
}
