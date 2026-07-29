<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MachineSummary',
    required: [
        'id', 'public_id', 'serial_number', 'installed_at', 'is_active',
        'term', 'country_code', 'needs_maintenance',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 423),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid', example: '9c3a1f4f-9c80-4d2f-8a4f-c2b9b1c2e7a1'),
        new OA\Property(property: 'serial_number', type: 'string', example: 'PW50M-AE-001234'),
        new OA\Property(
            property: 'product',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 12),
                new OA\Property(property: 'code', type: 'string', example: 'PW50M'),
                new OA\Property(property: 'name', type: 'string', example: 'PW50M Filtration Unit'),
            ],
        ),
        new OA\Property(property: 'location_label', type: 'string', nullable: true, example: 'Eataly Main Kitchen - Dubai Mall'),
        new OA\Property(property: 'country_code', type: 'string', example: 'ae', minLength: 2, maxLength: 2),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date', example: '2025-03-12'),
        new OA\Property(property: 'next_maintenance_due_at', type: 'string', format: 'date', nullable: true, example: '2026-06-12'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(
            property: 'address',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 17),
                new OA\Property(property: 'label', type: 'string', example: 'Eataly Main Kitchen'),
                new OA\Property(property: 'city', type: 'string', example: 'Dubai'),
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
        new OA\Property(property: 'needs_maintenance', type: 'boolean', example: true,
            description: 'True when next_maintenance_due_at is within 30 days or overdue (active machines only).'),
        new OA\Property(property: 'product_price', type: 'object', nullable: true,
            description: 'Current active price for this product matching the machine term.',
            properties: [
                new OA\Property(property: 'term', type: 'string', example: 'rent'),
                new OA\Property(property: 'amount', type: 'string', example: '450.00'),
                new OA\Property(property: 'currency', type: 'string', example: 'AED'),
                new OA\Property(property: 'period', type: 'string', nullable: true, example: 'monthly'),
                new OA\Property(property: 'min_term_months', type: 'integer', nullable: true, example: 12),
            ],
        ),
        new OA\Property(property: 'rental_price', type: 'string', nullable: true, example: '450.00',
            description: 'Unit price from the associated order line item (rental only).'),
        new OA\Property(property: 'rental_total', type: 'string', nullable: true, example: '450.00',
            description: 'Line total from the associated order line item (rental only).'),
    ],
    type: 'object',
)]
final class MachineSummary
{
    // Documentation-only.
}
