<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Templates;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateNotificationTemplateRequest',
    type: 'object',
    required: ['subject', 'body', 'is_active'],
    properties: [
        new OA\Property(
            property: 'subject',
            type: 'object',
            required: ['en', 'ar'],
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 255, example: 'Your order is on its way!'),
                new OA\Property(property: 'ar', type: 'string', maxLength: 255, example: 'طلبك في الطريق إليك!'),
            ],
        ),
        new OA\Property(
            property: 'body',
            type: 'object',
            required: ['en', 'ar'],
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 8000),
                new OA\Property(property: 'ar', type: 'string', maxLength: 8000),
            ],
        ),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
)]
final class UpdateNotificationTemplateRequest
{
    // Documentation-only.
}
