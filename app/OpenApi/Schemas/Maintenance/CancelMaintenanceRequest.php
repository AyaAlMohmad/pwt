<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CancelMaintenanceRequest',
    required: ['cancellation_reason'],
    properties: [
        new OA\Property(property: 'cancellation_reason', type: 'string', minLength: 5, maxLength: 500,
            example: 'I solved it myself — no need to dispatch a technician.'),
    ],
    type: 'object',
)]
final class CancelMaintenanceRequest
{
    // Documentation-only.
}
