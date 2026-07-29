<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\AuditLogs;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuditLogEntry',
    type: 'object',
    required: ['id', 'description', 'created_at', 'causer'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 8421),
        new OA\Property(property: 'log_name', type: 'string', nullable: true, example: 'admin.orders'),
        new OA\Property(property: 'description', type: 'string', example: 'order.status.updated'),
        new OA\Property(property: 'event', type: 'string', nullable: true, example: 'updated'),
        new OA\Property(property: 'subject_type', type: 'string', nullable: true, example: 'App\\Models\\Order'),
        new OA\Property(property: 'subject_id', type: 'integer', nullable: true, example: 1057),
        new OA\Property(property: 'causer', ref: '#/components/schemas/AuditLogCauser'),
        new OA\Property(property: 'properties', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
final class AuditLogEntry
{
    // Documentation-only.
}
