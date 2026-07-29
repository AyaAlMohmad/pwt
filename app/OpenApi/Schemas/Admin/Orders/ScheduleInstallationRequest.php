<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ScheduleInstallationRequest',
    properties: [
        new OA\Property(property: 'scheduled_for_at', type: 'string', format: 'date-time', nullable: true, example: '2026-05-20T10:00:00+04:00', description: 'Optional; when omitted, falls back to the order\'s delivery_date + delivery_time_slot.'),
        new OA\Property(property: 'technician_user_id', type: 'integer', nullable: true, example: 89, description: 'Must reference an active user with the operations role (MVP); the dedicated technician role lands in Phase 2.'),
        new OA\Property(property: 'installation_notes', type: 'string', maxLength: 1000, nullable: true, example: 'Bring spare filter cartridge'),
    ],
    type: 'object',
)]
final class ScheduleInstallationRequest
{
    // Documentation-only.
}
