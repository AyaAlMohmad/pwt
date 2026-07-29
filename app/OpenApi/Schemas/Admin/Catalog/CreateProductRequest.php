<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Request body for POST /api/v1/admin/products.
 *
 * `prices` and `specs` are optional inline arrays — if provided, they
 * are inserted alongside the product in the same transaction (Phase 16
 * § 4.3 / § 3.5.1).
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'CreateProductRequest',
    required: ['code', 'category_id', 'name'],
    properties: [
        new OA\Property(property: 'code', type: 'string', minLength: 2, maxLength: 80, example: 'PW100M'),
        new OA\Property(property: 'category_id', type: 'integer', example: 1),
        new OA\Property(
            property: 'name',
            type: 'object',
            required: ['en'],
            properties: [
                new OA\Property(property: 'en', type: 'string', minLength: 2, maxLength: 200),
                new OA\Property(property: 'ar', type: 'string', nullable: true, minLength: 2, maxLength: 200),
            ],
        ),
        new OA\Property(
            property: 'short_description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true, maxLength: 500),
                new OA\Property(property: 'ar', type: 'string', nullable: true, maxLength: 500),
            ],
        ),
        new OA\Property(
            property: 'description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true, maxLength: 10000),
                new OA\Property(property: 'ar', type: 'string', nullable: true, maxLength: 10000),
            ],
        ),
        new OA\Property(
            property: 'benefits',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'array', items: new OA\Items(type: 'string', maxLength: 200)),
                new OA\Property(property: 'ar', type: 'array', items: new OA\Items(type: 'string', maxLength: 200)),
            ],
        ),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'is_featured', type: 'boolean', example: false),
        new OA\Property(property: 'is_quote_only', type: 'boolean', example: false),
        new OA\Property(property: 'is_machine_eligible', type: 'boolean', example: false),
        new OA\Property(property: 'stock_status', type: 'string', enum: ['in_stock', 'out_of_stock', 'coming_soon', 'discontinued']),
        new OA\Property(property: 'display_order', type: 'integer', minimum: 0, maximum: 65535),
        new OA\Property(
            property: 'prices',
            type: 'array',
            maxItems: 24,
            items: new OA\Items(
                type: 'object',
                required: ['term', 'amount', 'currency'],
                properties: [
                    new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0),
                    new OA\Property(property: 'currency', type: 'string', enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR']),
                    new OA\Property(property: 'period', type: 'string', enum: ['monthly', 'yearly'], nullable: true),
                    new OA\Property(property: 'min_term_months', type: 'integer', minimum: 1, maximum: 120, nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ],
            ),
            description: 'Replace-all semantics: if omitted, no prices are touched. If sent as an empty array, all prices are removed (product must be quote-only).',
        ),
        new OA\Property(
            property: 'specs',
            type: 'array',
            maxItems: 50,
            items: new OA\Items(
                type: 'object',
                required: ['label', 'value'],
                properties: [
                    new OA\Property(
                        property: 'label',
                        type: 'object',
                        required: ['en'],
                        properties: [
                            new OA\Property(property: 'en', type: 'string', maxLength: 120),
                            new OA\Property(property: 'ar', type: 'string', nullable: true),
                        ],
                    ),
                    new OA\Property(
                        property: 'value',
                        type: 'object',
                        required: ['en'],
                        properties: [
                            new OA\Property(property: 'en', type: 'string', maxLength: 200),
                            new OA\Property(property: 'ar', type: 'string', nullable: true),
                        ],
                    ),
                    new OA\Property(property: 'display_order', type: 'integer'),
                ],
            ),
        ),
    ],
    type: 'object',
)]
final class CreateProductRequest
{
    // Documentation-only.
}
