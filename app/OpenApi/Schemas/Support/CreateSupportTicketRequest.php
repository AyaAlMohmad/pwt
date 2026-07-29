<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateSupportTicketRequest',
    required: ['subject', 'category', 'message'],
    properties: [
        new OA\Property(property: 'subject', type: 'string', minLength: 3, maxLength: 200,
            example: 'Order #1057 — installation date question'),
        new OA\Property(property: 'category', ref: '#/components/schemas/SupportTicketCategory'),
        new OA\Property(property: 'message', type: 'string', minLength: 10, maxLength: 5000,
            example: 'When will my installation be scheduled? I placed the order yesterday.'),
        new OA\Property(property: 'related_order_id', type: 'integer', nullable: true, example: 1057,
            description: 'Optional. Must be an order owned by the caller.'),
        new OA\Property(property: 'related_machine_id', type: 'integer', nullable: true, example: 423,
            description: 'Optional. Must be a machine owned by the caller.'),
        new OA\Property(
            property: 'attachment_file_ids',
            type: 'array',
            nullable: true,
            maxItems: 5,
            items: new OA\Items(type: 'integer'),
            description: 'Up to 5 file ids (must be type=support_attachment, owned by caller, not yet attached).',
        ),
    ],
    type: 'object',
)]
final class CreateSupportTicketRequest
{
    // Documentation-only.
}
