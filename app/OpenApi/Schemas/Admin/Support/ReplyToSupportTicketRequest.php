<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReplyToSupportTicketRequest',
    required: ['body'],
    description: 'Admin reply on a support ticket. Set `is_internal=true` to post an admin-only note (invisible to customer; permitted even on terminal tickets).',
    properties: [
        new OA\Property(property: 'body', type: 'string', minLength: 1, maxLength: 5000,
            example: 'We have rescheduled the installation for Saturday — please confirm.'),
        new OA\Property(property: 'is_internal', type: 'boolean', default: false,
            description: 'When true the reply is admin-only and never surfaces to the customer.'),
        new OA\Property(
            property: 'attachment_file_ids',
            type: 'array',
            maxItems: 5,
            items: new OA\Items(type: 'integer', example: 412),
            description: 'Up to 5 pre-uploaded support_attachment file ids owned by the authenticated admin.',
        ),
    ],
    type: 'object',
)]
final class ReplyToSupportTicketRequest
{
    // Documentation-only.
}
