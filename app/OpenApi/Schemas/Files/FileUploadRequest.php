<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Files;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FileUploadRequest',
    description: 'Multipart upload body. Image files (jpeg/png/webp) or PDF, max 5 MB.',
    required: ['file', 'type'],
    properties: [
        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'The file payload. ≤ 5 MB. Allowed mime types: image/jpeg, image/png, image/webp, application/pdf.'),
        new OA\Property(property: 'type', type: 'string', enum: [
            'profile_avatar', 'maintenance_media', 'product_image',
            'support_attachment', 'order_attachment', 'generic',
        ], example: 'maintenance_media'),
    ],
    type: 'object',
)]
final class FileUploadRequest
{
    // Documentation-only.
}
