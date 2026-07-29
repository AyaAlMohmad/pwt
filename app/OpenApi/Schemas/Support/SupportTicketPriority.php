<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketPriority',
    type: 'string',
    enum: ['low', 'medium', 'high', 'urgent'],
    description: 'Internal priority of a support ticket. Customer submissions always start at `medium`; admins elevate via Phase 19.',
)]
final class SupportTicketPriority
{
    // Documentation-only.
}
