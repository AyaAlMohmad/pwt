<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Rfq;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminRfqResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'company_name', type: 'string', example: 'Acme Facilities Ltd'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ahmed@company.com'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '501234567'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(property: 'processed', type: 'boolean', example: false),
        new OA\Property(property: 'processed_by', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
        ]),
        new OA\Property(property: 'processed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
final class AdminRfqResponse
{
    // Documentation-only.
}
