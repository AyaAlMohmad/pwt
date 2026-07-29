<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/SupportTicket'),
        new OA\Schema(
            properties: [
                new OA\Property(property: 'resolution_summary', type: 'string', nullable: true,
                    description: 'Only returned when status is `resolved` or `closed`.'),
                new OA\Property(
                    property: 'related_order',
                    ref: '#/components/schemas/SupportTicketRelatedOrderSummary',
                    nullable: true,
                ),
                new OA\Property(
                    property: 'related_machine',
                    ref: '#/components/schemas/SupportTicketRelatedMachineSummary',
                    nullable: true,
                ),
                new OA\Property(
                    property: 'messages',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/SupportTicketMessage'),
                ),
                new OA\Property(
                    property: 'status_history',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/SupportTicketStatusHistoryEntry'),
                ),
                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
            ],
            type: 'object',
        ),
    ],
)]
final class SupportTicketDetail
{
    // Documentation-only.
}
