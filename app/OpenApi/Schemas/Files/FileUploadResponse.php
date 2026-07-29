<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Files;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FileUploadResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'File uploaded'),
        new OA\Property(property: 'data', ref: '#/components/schemas/File'),
    ],
    type: 'object',
)]
final class FileUploadResponse
{
    // Documentation-only.
}
