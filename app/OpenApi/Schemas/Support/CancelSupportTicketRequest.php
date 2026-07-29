<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CancelSupportTicketRequest',
    required: ['cancellation_reason'],
    properties: [
        new OA\Property(property: 'cancellation_reason', type: 'string', maxLength: 500,
            example: 'I figured out the issue myself — please cancel this ticket.'),
    ],
    type: 'object',
)]
final class CancelSupportTicketRequest
{
    // Documentation-only.
}
