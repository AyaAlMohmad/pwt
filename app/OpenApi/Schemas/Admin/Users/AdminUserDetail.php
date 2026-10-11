<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Users;

use App\Http\Resources\Api\V1\Admin\Users\AdminUserListResource;
use OpenApi\Attributes as OA;

/**
 * Single user payload returned by the admin-users module.
 *
 * Mirrors {@see AdminUserListResource}
 * exactly. Used as the item shape in `index` and the top-level data shape
 * in `show`, `update`, and `destroy`.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminUserDetail',
    required: [
        'id',
        'name',
        'phone_country_code',
        'locale',
        'is_active',
        'role',
        'created_at',
        'counts',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'name', type: 'string', example: 'Sara Ahmed'),
        new OA\Property(property: 'first_name', type: 'string', nullable: true, example: 'Sara'),
        new OA\Property(property: 'last_name', type: 'string', nullable: true, example: 'Ahmed'),
        new OA\Property(property: 'company_name', type: 'string', nullable: true, example: 'PWT Trading LLC'),
        new OA\Property(property: 'contact_name', type: 'string', nullable: true, example: 'Sara Ahmed'),
        new OA\Property(property: 'contact_email', type: 'string', format: 'email', nullable: true, example: 'contact@pwt.example'),
        new OA\Property(property: 'type', type: 'string', enum: ['individual', 'company'], nullable: true, example: 'company'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'sara@pwt.example'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '501112233'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971'),
        new OA\Property(property: 'phone_e164', type: 'string', nullable: true, example: '+971501112233'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], example: 'en'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(
            property: 'role',
            type: 'string',
            enum: ['super_admin', 'operations', 'sales', 'support', 'customer'],
            example: 'operations',
        ),
        new OA\Property(property: 'avatar_file_id', type: 'integer', nullable: true, example: 91),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true, example: '2026-08-17T10:00:00+00:00'),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true, example: '2026-05-15T09:30:00+00:00'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-02-01T08:00:00+00:00'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true, example: null),
        new OA\Property(property: 'counts', ref: '#/components/schemas/AdminUserCounts'),
        new OA\Property(property: 'emirate', type: 'string', nullable: true, example: 'Dubai'),
        // Default address book entry (is_default, else the first row).
        // Present on both index and show; null when the customer has none.
        new OA\Property(property: 'default_address', ref: '#/components/schemas/Address', nullable: true),
        // Full address book — `show`, `store`, `update` and `destroy`
        // only. The index shape omits it.
        new OA\Property(
            property: 'addresses',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Address'),
        ),
    ],
    type: 'object',
)]
final class AdminUserDetail
{
    // Documentation-only.
}
