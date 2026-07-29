<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicket',
    required: ['id', 'public_id', 'subject', 'category', 'priority', 'status', 'opened_at', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 184),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid',
            example: 'b9d2c7e1-3f44-4af0-9c1a-12aabc77ee10'),
        new OA\Property(property: 'subject', type: 'string',
            example: 'Order #1057 — installation date question'),
        new OA\Property(property: 'category', ref: '#/components/schemas/SupportTicketCategory'),
        new OA\Property(property: 'priority', ref: '#/components/schemas/SupportTicketPriority'),
        new OA\Property(property: 'status', ref: '#/components/schemas/SupportTicketStatus'),
        new OA\Property(property: 'assigned_to_first_name', type: 'string', nullable: true,
            example: 'Hassan',
            description: 'Anonymised first-name only; null when unassigned.'),
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time',
            example: '2026-05-15T11:05:00+04:00'),
        new OA\Property(property: 'first_response_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_message_at', type: 'string', format: 'date-time', nullable: true,
            description: 'Timestamp of the latest non-internal-note message. Drives the inbox sort.'),
        new OA\Property(property: 'unread_message_count', type: 'integer', example: 0,
            description: 'Phase 12 ships this as always-zero (0). Phase 19 will wire the real count once `last_customer_view_at` lands.'),
        new OA\Property(property: 'is_cancellable', type: 'boolean', example: true,
            description: 'Drives the UI cancel-button visibility — equivalent to `status==open`.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time',
            example: '2026-05-15T11:05:00+04:00'),
    ],
    type: 'object',
)]
final class SupportTicket
{
    // Documentation-only.
}
