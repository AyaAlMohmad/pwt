<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SupportTicketRelatedMachineSummary',
    required: ['id', 'public_id', 'serial_number'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 423),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid',
            example: 'a4b5c6d7-e8f9-4a3b-9c1d-2e3f4a5b6c7d'),
        new OA\Property(property: 'serial_number', type: 'string', example: 'PW50M-AE-001234'),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date', nullable: true,
            example: '2025-09-12'),
        new OA\Property(
            property: 'product',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'PW50M'),
                new OA\Property(property: 'name', type: 'string', example: 'PW50M Filtration Unit'),
            ],
        ),
    ],
    type: 'object',
    description: 'Limited machine fields exposed inside a support ticket detail.',
)]
final class SupportTicketRelatedMachineSummary
{
    // Documentation-only.
}
