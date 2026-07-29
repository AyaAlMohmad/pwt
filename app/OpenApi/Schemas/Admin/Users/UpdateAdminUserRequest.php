<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Users;

use OpenApi\Attributes as OA;

/**
 * Request body for PATCH /api/v1/admin/users/{user}.
 *
 * All fields are optional but at least one MUST be present. Mirrors
 * {@see \App\Http\Requests\Api\V1\Admin\Users\UpdateAdminUserRequest}.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'UpdateAdminUserRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 120, example: 'Sara Ahmed'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 200, example: 'sara@pwt.example'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 20, example: '501112233'),
        new OA\Property(property: 'phone_country_code', type: 'string', maxLength: 8, pattern: '^\\+\\d{1,4}$', example: '+971'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], example: 'en'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(
            property: 'role',
            type: 'string',
            description: 'Role change requires the caller to be super_admin; otherwise 403 ERR_FORBIDDEN. Cannot be applied to self (422 ERR_CANNOT_CHANGE_OWN_ROLE).',
            enum: ['super_admin', 'operations', 'sales', 'support', 'customer'],
            example: 'operations',
        ),
    ],
    type: 'object',
)]
final class UpdateAdminUserRequest
{
    // Documentation-only.
}
