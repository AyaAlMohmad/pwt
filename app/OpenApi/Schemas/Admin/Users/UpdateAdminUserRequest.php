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
        new OA\Property(property: 'first_name', type: 'string', nullable: true, minLength: 1, maxLength: 60, example: 'Sara'),
        new OA\Property(property: 'last_name', type: 'string', nullable: true, minLength: 1, maxLength: 60, example: 'Ahmed'),
        new OA\Property(property: 'account_type', type: 'string', enum: ['individual', 'company'], example: 'company'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 200, example: 'sara@pwt.example'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 20, example: '501112233'),
        new OA\Property(property: 'phone_country_code', type: 'string', maxLength: 8, pattern: '^\\+\\d{1,4}$', example: '+971'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], example: 'en'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'company_name', type: 'string', nullable: true, maxLength: 255, example: 'PWT Trading LLC'),
        new OA\Property(property: 'contact_name', type: 'string', nullable: true, minLength: 2, maxLength: 120, example: 'Sara Ahmed'),
        new OA\Property(property: 'contact_email', type: 'string', format: 'email', nullable: true, maxLength: 200, example: 'contact@pwt.example'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'NewPass123!'),
        new OA\Property(property: 'avatar_file_id', type: 'integer', nullable: true, description: 'Must reference an existing files row; null clears the avatar.', example: 91),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true, description: 'Accepts a timestamp, or the boolean shorthand true (= now) / false (= null).', example: '2026-08-17T10:00:00+00:00'),
        new OA\Property(
            property: 'role',
            type: 'string',
            description: 'Role change requires the caller to be super_admin; otherwise 403 ERR_FORBIDDEN. Cannot be applied to self (422 ERR_CANNOT_CHANGE_OWN_ROLE).',
            enum: ['super_admin', 'operations', 'sales', 'support', 'customer'],
            example: 'operations',
        ),
        new OA\Property(
            property: 'address',
            description: 'Edits the customer address book. Send `id` to target a specific row (must belong to the user); omit it to edit the current default address, or to create one when the user has none — in that case `label` and `line1` are required. Setting `is_default: true` demotes the previous default atomically.',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 17),
                new OA\Property(property: 'label', type: 'string', maxLength: 60, example: 'Main kitchen'),
                new OA\Property(property: 'country_id', type: 'integer', nullable: true, example: 1),
                new OA\Property(property: 'region_id', type: 'integer', nullable: true, example: 3),
                new OA\Property(property: 'recipient_name', type: 'string', nullable: true, maxLength: 120, example: 'Hisham'),
                new OA\Property(property: 'recipient_phone', type: 'string', nullable: true, maxLength: 20, example: '501112233'),
                new OA\Property(property: 'recipient_phone_country_code', type: 'string', nullable: true, maxLength: 6, pattern: '^\\+?\\d{1,5}$', example: '+971'),
                new OA\Property(property: 'line1', type: 'string', maxLength: 200, example: 'Mall of the Emirates, Level 1'),
                new OA\Property(property: 'line2', type: 'string', nullable: true, maxLength: 200, example: null),
                new OA\Property(property: 'city', type: 'string', nullable: true, maxLength: 100, example: 'Dubai'),
                new OA\Property(property: 'area', type: 'string', nullable: true, maxLength: 100, example: 'Al Barsha'),
                new OA\Property(property: 'postal_code', type: 'string', nullable: true, maxLength: 20, example: null),
                new OA\Property(property: 'notes', type: 'string', nullable: true, maxLength: 1000, example: null),
                new OA\Property(property: 'is_default', type: 'boolean', example: true),
                new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, example: 25.1181),
                new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, example: 55.2003),
            ],
        ),
    ],
    type: 'object',
)]
final class UpdateAdminUserRequest
{
    // Documentation-only.
}
