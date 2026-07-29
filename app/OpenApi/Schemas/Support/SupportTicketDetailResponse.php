<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketDetailResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Support ticket created.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/SupportTicketDetail'),
    ],
    type: 'object',
)]
final class SupportTicketDetailResponse
{
    // Documentation-only.
}
