<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketStatus',
    type: 'string',
    enum: ['open', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'],
    description: 'Lifecycle status of a support ticket. Terminal states: closed, cancelled.',
)]
final class SupportTicketStatus
{
    // Documentation-only.
}
