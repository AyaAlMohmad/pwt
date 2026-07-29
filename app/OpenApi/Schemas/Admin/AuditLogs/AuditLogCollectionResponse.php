<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\AuditLogs;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuditLogCollectionResponse',
    type: 'object',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Audit logs retrieved.'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AuditLogEntry'),
        ),
        new OA\Property(property: 'meta', type: 'object',
            properties: [
                new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
            ],
        ),
    ],
)]
final class AuditLogCollectionResponse
{
    // Documentation-only.
}
