<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

/**
 * Customer summary embedded inside AdminOrderDetail.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminOrderCustomer',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 412),
        new OA\Property(property: 'name', type: 'string', example: 'Sarah Al-Mansouri'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'sarah@example.ae'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', nullable: true, example: '+971'),
        new OA\Property(property: 'phone_e164', type: 'string', nullable: true, example: '+971501234567'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], example: 'en'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'orders_count', type: 'integer', nullable: true, example: 4),
        new OA\Property(property: 'support_tickets_count', type: 'integer', nullable: true, example: 0),
        new OA\Property(property: 'machines_count', type: 'integer', nullable: true, example: 2),
    ],
    type: 'object',
)]
final class AdminOrderCustomer
{
    // Documentation-only.
}
