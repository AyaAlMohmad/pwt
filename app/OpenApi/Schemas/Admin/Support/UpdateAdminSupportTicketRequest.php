<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateAdminSupportTicketRequest',
    description: 'Admin update to a support ticket. At least one of `status`, `priority`, `assigned_to_user_id` must be supplied. `status=resolved` requires `resolution_summary`. `status=cancelled` requires `cancellation_reason`. `status=assigned` requires `assigned_to_user_id` non-null.',
    properties: [
        new OA\Property(property: 'status', type: 'string',
            enum: ['assigned', 'in_progress', 'resolved', 'closed', 'cancelled'],
            description: 'Target status. `open` rejected (reopen is Phase 2).'),
        new OA\Property(property: 'priority', type: 'string',
            enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'assigned_to_user_id', type: 'integer', nullable: true,
            description: 'Admin user id with role `support` or `super_admin`. Null un-assigns.'),
        new OA\Property(property: 'resolution_summary', type: 'string', nullable: true,
            minLength: 5, maxLength: 2000,
            description: 'Required when status=resolved.'),
        new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true,
            minLength: 3, maxLength: 500,
            description: 'Required when status=cancelled.'),
        new OA\Property(property: 'note', type: 'string', nullable: true,
            minLength: 3, maxLength: 500,
            description: 'Optional free-form note written to the status history.'),
    ],
    type: 'object',
)]
final class UpdateAdminSupportTicketRequest
{
    // Documentation-only.
}
