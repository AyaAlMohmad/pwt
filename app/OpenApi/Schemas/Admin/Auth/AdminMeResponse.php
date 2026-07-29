<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMeResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(
            property: 'data',
            required: ['user', 'role', 'permissions', 'capabilities'],
            properties: [
                new OA\Property(property: 'user', ref: '#/components/schemas/AdminUser'),
                new OA\Property(property: 'role', type: 'string', enum: ['super_admin', 'operations', 'sales', 'support']),
                new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['orders.view', 'orders.update-status']),
                new OA\Property(property: 'capabilities', ref: '#/components/schemas/AdminCapabilities'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class AdminMeResponse
{
    // Documentation-only.
}
