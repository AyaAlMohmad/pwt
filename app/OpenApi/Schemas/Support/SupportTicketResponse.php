<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Support ticket retrieved.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/SupportTicket'),
    ],
    type: 'object',
)]
final class SupportTicketResponse
{
    // Documentation-only.
}
