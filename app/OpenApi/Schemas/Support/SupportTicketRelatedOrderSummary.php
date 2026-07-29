<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketRelatedOrderSummary',
    required: ['id', 'public_id', 'status', 'total_amount', 'currency'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1057),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid',
            example: '0a3a1f4f-7c2b-4a3e-8be1-2c95c6e9d1bb'),
        new OA\Property(property: 'status', type: 'string', example: 'confirmed'),
        new OA\Property(property: 'total_amount', type: 'string', example: '1250.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
    ],
    type: 'object',
    description: 'Limited order fields exposed inside a support ticket detail. Customers can deep-link via `GET /orders/{id}` for the rest.',
)]
final class SupportTicketRelatedOrderSummary
{
    // Documentation-only.
}
