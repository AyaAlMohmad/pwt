<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketMessage',
    required: ['id', 'sender_type', 'body', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 940),
        new OA\Property(property: 'sender_type', type: 'string',
            enum: ['customer', 'admin', 'system']),
        new OA\Property(property: 'sender_first_name', type: 'string',
            example: 'Aisha',
            description: 'Anonymised first-name only; system messages render as "System".'),
        new OA\Property(property: 'body', type: 'string',
            example: 'When will my installation be scheduled? I placed the order yesterday.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time',
            example: '2026-05-15T11:05:00+04:00'),
        new OA\Property(
            property: 'attachments',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/SupportTicketAttachment'),
        ),
    ],
    type: 'object',
    description: 'A single customer-visible message within the ticket thread. Internal admin notes (`is_internal_note=true`) are NEVER included.',
)]
final class SupportTicketMessage
{
    // Documentation-only.
}
