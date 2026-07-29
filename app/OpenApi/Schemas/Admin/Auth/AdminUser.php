<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminUser',
    required: ['id', 'name', 'email', 'role', 'locale', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'name', type: 'string', example: 'Sara Ahmed'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'sara@pwt.example'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+971501112233'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971'),
        new OA\Property(property: 'role', type: 'string', enum: ['super_admin', 'operations', 'sales', 'support'], example: 'operations'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], example: 'en'),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true, example: '2026-05-15T09:30:00+00:00'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-02-01T08:00:00+00:00'),
    ],
    type: 'object',
)]
final class AdminUser
{
    // Documentation-only.
}
