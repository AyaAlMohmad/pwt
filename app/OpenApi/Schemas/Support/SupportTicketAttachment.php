<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketAttachment',
    required: ['id', 'file_id'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 21),
        new OA\Property(property: 'file_id', type: 'integer', example: 412),
        new OA\Property(property: 'file_uuid', type: 'string', format: 'uuid', nullable: true,
            example: '01HZX0Q4N9F7VT5XK8Y3CZRMR1'),
        new OA\Property(property: 'filename', type: 'string', nullable: true, example: 'kitchen-photo.jpg'),
        new OA\Property(property: 'mime', type: 'string', nullable: true, example: 'image/jpeg'),
        new OA\Property(property: 'size', type: 'integer', nullable: true, example: 124356,
            description: 'Size in bytes.'),
        new OA\Property(property: 'download_url', type: 'string', format: 'uri', nullable: true,
            example: 'http://127.0.0.1:8000/api/v1/files/01HZX0Q4N9F7VT5XK8Y3CZRMR1/download?expires=…&signature=…',
            description: 'Short-TTL signed download URL (10 minutes).'),
    ],
    type: 'object',
)]
final class SupportTicketAttachment
{
    // Documentation-only.
}
