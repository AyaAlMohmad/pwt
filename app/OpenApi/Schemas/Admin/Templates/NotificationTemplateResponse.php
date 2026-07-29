<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Templates;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationTemplateResponse',
    type: 'object',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Notification template updated.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/NotificationTemplateAdmin'),
    ],
)]
final class NotificationTemplateResponse
{
    // Documentation-only.
}
