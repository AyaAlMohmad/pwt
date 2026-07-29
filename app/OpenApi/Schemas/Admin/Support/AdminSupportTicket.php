<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportTicket',
    required: ['id', 'public_id', 'subject', 'category', 'status', 'priority', 'opened_at', 'is_trashed', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 184),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid',
            example: 'b9d2c7e1-3f44-4af0-9c1a-12aabc77ee10'),
        new OA\Property(property: 'subject', type: 'string',
            example: 'Order #1057 — installation date question'),
        new OA\Property(property: 'category', ref: '#/components/schemas/SupportTicketCategory'),
        new OA\Property(property: 'status', ref: '#/components/schemas/SupportTicketStatus'),
        new OA\Property(property: 'priority', ref: '#/components/schemas/SupportTicketPriority'),
        new OA\Property(property: 'customer', ref: '#/components/schemas/AdminSupportCustomer', nullable: true),
        new OA\Property(property: 'assigned_to', ref: '#/components/schemas/AdminSupportActor', nullable: true),
        new OA\Property(property: 'related_order_id', type: 'integer', nullable: true, example: 1057),
        new OA\Property(property: 'related_machine_id', type: 'integer', nullable: true, example: null),
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time',
            example: '2026-05-15T11:05:00+04:00'),
        new OA\Property(property: 'first_response_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_message_at', type: 'string', format: 'date-time', nullable: true,
            description: 'Denormalised — latest message timestamp regardless of internal-note flag.'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'message_count', type: 'integer', example: 4),
        new OA\Property(property: 'internal_note_count', type: 'integer', example: 1),
        new OA\Property(property: 'is_trashed', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminSupportTicket
{
    // Documentation-only.
}
