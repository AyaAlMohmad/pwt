<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrderDetail',
    required: ['id', 'public_id', 'status', 'term', 'payment_method', 'currency', 'total_amount', 'placed_at', 'is_trashed', 'items', 'status_history'],
    allOf: [
        new OA\Schema(ref: '#/components/schemas/AdminOrder'),
        new OA\Schema(
            properties: [
                new OA\Property(property: 'subtotal_amount', type: 'string', example: '2250.00'),
                new OA\Property(property: 'discount_amount', type: 'string', example: '0.00'),
                new OA\Property(property: 'tax_amount', type: 'string', example: '112.50'),
                new OA\Property(property: 'tax_rate', type: 'number', format: 'float', example: 5.00),
                new OA\Property(property: 'shipping_amount', type: 'string', example: '0.00'),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
                new OA\Property(property: 'cancellation_reason', type: 'string', nullable: true),
                new OA\Property(
                    property: 'cancelled_by',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string'),
                    ],
                ),
                new OA\Property(property: 'customer', ref: '#/components/schemas/AdminOrderCustomer', nullable: true),
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/AdminOrderItem'),
                ),
                new OA\Property(
                    property: 'status_history',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/AdminOrderStatusHistoryEntry'),
                ),
                new OA\Property(property: 'delivery_address', type: 'object', nullable: true),
                new OA\Property(property: 'billing_address', type: 'object', nullable: true),
                new OA\Property(
                    property: 'applied_promotion',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'code', type: 'string'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'type', type: 'string'),
                        new OA\Property(property: 'value', type: 'number', format: 'float'),
                        new OA\Property(property: 'applied_amount', type: 'string'),
                        new OA\Property(property: 'currency', type: 'string'),
                    ],
                ),
                new OA\Property(property: 'payment', ref: '#/components/schemas/AdminOrderPayment', nullable: true),
                new OA\Property(property: 'installation', ref: '#/components/schemas/AdminOrderInstallation', nullable: true),
            ],
            type: 'object',
        ),
    ],
)]
final class AdminOrderDetail
{
    // Documentation-only.
}
