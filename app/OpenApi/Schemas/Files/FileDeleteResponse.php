<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Files;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FileDeleteResponse',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'File deleted'),
        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
    ],
    type: 'object',
)]
final class FileDeleteResponse
{
    // Documentation-only.
}
