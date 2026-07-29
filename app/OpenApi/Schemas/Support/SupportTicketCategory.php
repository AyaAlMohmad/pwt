<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketCategory',
    type: 'string',
    enum: [
        'order_issue',
        'payment_issue',
        'maintenance_issue',
        'product_question',
        'account_issue',
        'feedback',
        'other',
    ],
    description: 'Customer-selected category bucket for the ticket.',
)]
final class SupportTicketCategory
{
    // Documentation-only.
}
