<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\AuditLogs;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuditLogCauser',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', nullable: true, example: 7),
        new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Layla Operations'),
        new OA\Property(property: 'email', type: 'string', nullable: true, example: 'layla@pwt.example'),
        new OA\Property(property: 'role', type: 'string', nullable: true, example: 'operations'),
    ],
)]
final class AuditLogCauser
{
    // Documentation-only.
}
