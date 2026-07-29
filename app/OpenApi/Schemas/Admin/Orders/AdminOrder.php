<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrder',
    required: ['id', 'public_id', 'status', 'term', 'payment_method', 'currency', 'total_amount', 'items_count', 'placed_at', 'is_trashed'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1057),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'scheduled', 'out_for_delivery', 'installed', 'completed', 'cancelled']),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod']),
        new OA\Property(property: 'payment_status', type: 'string', nullable: true, enum: ['initiated', 'authorized', 'captured', 'failed', 'cancelled', 'refunded']),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'total_amount', type: 'string', example: '2362.50'),
        new OA\Property(property: 'items_count', type: 'integer', example: 1),
        new OA\Property(
            property: 'customer',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 412),
                new OA\Property(property: 'name', type: 'string', example: 'Sarah Al-Mansouri'),
                new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+971501234567'),
            ],
        ),
        new OA\Property(property: 'country_code', type: 'string', nullable: true, example: 'AE'),
        new OA\Property(property: 'placed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'confirmed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'scheduled_for_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_trashed', type: 'boolean', example: false),
    ],
    type: 'object',
)]
final class AdminOrder
{
    // Documentation-only.
}
