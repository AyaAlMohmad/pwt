<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Files;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'File',
    required: ['id', 'uuid', 'url', 'mime', 'size', 'type', 'is_public', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid', example: '0d3a6f0c-2bf1-4f8b-9a9b-3c8e1f1c2d3e'),
        new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'http://127.0.0.1:8000/api/v1/files/0d3a6f0c-2bf1-4f8b-9a9b-3c8e1f1c2d3e/download?expires=1747300000&signature=abc...'),
        new OA\Property(property: 'mime', type: 'string', example: 'image/png'),
        new OA\Property(property: 'size', type: 'integer', example: 124356, description: 'Size in bytes.'),
        new OA\Property(property: 'type', type: 'string', enum: [
            'profile_avatar', 'maintenance_media', 'product_image',
            'support_attachment', 'order_attachment', 'generic',
        ], example: 'maintenance_media'),
        new OA\Property(property: 'is_public', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T09:30:00+04:00'),
    ],
    type: 'object',
)]
final class File
{
    // Documentation-only.
}
