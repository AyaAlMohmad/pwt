<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportTicketDetail',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/AdminSupportTicket'),
        new OA\Schema(
            type: 'object',
            properties: [
                new OA\Property(property: 'resolution_summary', type: 'string', nullable: true),
                new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true),
                new OA\Property(property: 'cancelled_by', ref: '#/components/schemas/AdminSupportActor', nullable: true),
                new OA\Property(property: 'related_order', ref: '#/components/schemas/AdminSupportRelatedOrder', nullable: true),
                new OA\Property(property: 'related_machine', ref: '#/components/schemas/AdminSupportRelatedMachine', nullable: true),
                new OA\Property(
                    property: 'messages',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/AdminSupportTicketMessage'),
                    description: 'Full thread INCLUDING is_internal_note=true rows. Admins only.',
                ),
                new OA\Property(
                    property: 'status_history',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/AdminSupportTicketStatusHistoryEntry'),
                ),
                new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
            ],
        ),
    ],
)]
final class AdminSupportTicketDetail
{
    // Documentation-only.
}
