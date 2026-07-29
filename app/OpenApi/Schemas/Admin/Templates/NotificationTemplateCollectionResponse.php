<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Templates;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationTemplateCollectionResponse',
    type: 'object',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Notification templates retrieved.'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/NotificationTemplateAdmin'),
        ),
        new OA\Property(property: 'meta', type: 'object',
            properties: [
                new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
            ],
        ),
    ],
)]
final class NotificationTemplateCollectionResponse
{
    // Documentation-only.
}
