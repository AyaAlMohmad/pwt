<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Order',
    required: ['id', 'public_id', 'status', 'term', 'payment_method', 'currency', 'subtotal_amount', 'total_amount', 'items_count', 'placed_at', 'tracking_progress', 'is_cancellable'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1057),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid', example: '0a3a1f4f-9c80-4d2f-8a4f-c2b9b1c2e7a1'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'scheduled', 'out_for_delivery', 'installed', 'completed', 'cancelled']),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod']),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'subtotal_amount', type: 'string', example: '2500.00'),
        new OA\Property(property: 'discount_amount', type: 'string', example: '250.00'),
        new OA\Property(property: 'tax_amount', type: 'string', example: '112.50'),
        new OA\Property(property: 'tax_rate', type: 'number', format: 'float', example: 5.00),
        new OA\Property(property: 'shipping_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'total_amount', type: 'string', example: '2362.50'),
        new OA\Property(property: 'items_count', type: 'integer', example: 1),
        new OA\Property(
            property: 'delivery_address',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 17),
                new OA\Property(property: 'label', type: 'string', example: 'Home'),
                new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Dubai'),
                new OA\Property(
                    property: 'country',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'code', type: 'string', example: 'AE'),
                        new OA\Property(property: 'name', type: 'string', example: 'United Arab Emirates'),
                    ],
                ),
            ],
        ),
        new OA\Property(
            property: 'promotion',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'WELCOME10'),
                new OA\Property(property: 'name', type: 'string', example: 'Welcome 10% off'),
            ],
        ),
        new OA\Property(property: 'delivery_date', type: 'string', format: 'date', nullable: true, example: '2026-06-20'),
        new OA\Property(property: 'delivery_time_slot', type: 'string', enum: ['morning', 'afternoon', 'evening'], nullable: true, example: 'morning'),
        new OA\Property(property: 'placed_at', type: 'string', format: 'date-time', example: '2026-05-15T11:00:00+04:00'),
        new OA\Property(property: 'confirmed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'scheduled_for_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true),
        new OA\Property(property: 'tracking_progress', type: 'integer', minimum: 0, maximum: 100, example: 10),
        new OA\Property(property: 'is_cancellable', type: 'boolean', example: true),
    ],
    type: 'object',
)]
final class Order
{
    // Documentation-only.
}
