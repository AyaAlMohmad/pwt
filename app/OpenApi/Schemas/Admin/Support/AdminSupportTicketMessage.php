<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportTicketMessage',
    required: ['id', 'sender_type', 'body', 'is_internal_note', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 940),
        new OA\Property(property: 'sender_type', type: 'string', enum: ['customer', 'admin', 'system']),
        new OA\Property(property: 'sender', ref: '#/components/schemas/AdminSupportActor', nullable: true),
        new OA\Property(property: 'body', type: 'string',
            example: "I'll check with the dispatch team and get back to you within 24h."),
        new OA\Property(property: 'is_internal_note', type: 'boolean', example: false,
            description: 'When true the message is admin-only and is NEVER surfaced to customers.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'attachments',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/SupportTicketAttachment'),
        ),
    ],
    type: 'object',
)]
final class AdminSupportTicketMessage
{
    // Documentation-only.
}
