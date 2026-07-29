<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Payments;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentWebhookResponse',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Webhook accepted'),
    ],
    type: 'object',
)]
final class PaymentWebhookResponse
{
    // Documentation-only.
}
