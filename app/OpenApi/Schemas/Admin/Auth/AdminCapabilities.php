<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminCapabilities',
    description: 'Fixed-shape capability map keyed by permission domain. Every domain and action is always present; values are booleans indicating whether the admin holds the underlying permission key. Stable shape regardless of role.',
    required: [
        'users', 'products', 'categories', 'orders', 'maintenance', 'machines',
        'support', 'promotions', 'banners', 'testimonials', 'reports', 'audit_logs', 'notifications',
    ],
    properties: [
        new OA\Property(property: 'users', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'deactivate', type: 'boolean'),
        ]),
        new OA\Property(property: 'products', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'deactivate', type: 'boolean'),
            new OA\Property(property: 'manage_images', type: 'boolean'),
        ]),
        new OA\Property(property: 'categories', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'delete', type: 'boolean'),
        ]),
        new OA\Property(property: 'orders', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'update_status', type: 'boolean'),
            new OA\Property(property: 'cancel', type: 'boolean'),
            new OA\Property(property: 'schedule', type: 'boolean'),
            new OA\Property(property: 'cancel_any', type: 'boolean'),
            new OA\Property(property: 'confirm', type: 'boolean'),
            new OA\Property(property: 'refund', type: 'boolean'),
        ]),
        new OA\Property(property: 'maintenance', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'assign', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'close', type: 'boolean'),
        ]),
        new OA\Property(property: 'machines', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
        ]),
        new OA\Property(property: 'support', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'reply', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'cancel', type: 'boolean'),
        ]),
        new OA\Property(property: 'promotions', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'delete', type: 'boolean'),
        ]),
        new OA\Property(property: 'banners', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'delete', type: 'boolean'),
        ]),
        new OA\Property(property: 'testimonials', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'create', type: 'boolean'),
            new OA\Property(property: 'update', type: 'boolean'),
            new OA\Property(property: 'delete', type: 'boolean'),
        ]),
        new OA\Property(property: 'reports', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'export', type: 'boolean'),
        ]),
        new OA\Property(property: 'audit_logs', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
        ]),
        new OA\Property(property: 'notifications', type: 'object', properties: [
            new OA\Property(property: 'view', type: 'boolean'),
            new OA\Property(property: 'templates', type: 'boolean'),
        ]),
    ],
    type: 'object',
)]
final class AdminCapabilities
{
    // Documentation-only.
}
