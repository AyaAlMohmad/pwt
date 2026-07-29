<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Request body for PUT /api/v1/admin/products/{product}.
 *
 * `code` is `prohibited` — the SKU is immutable post-creation.
 *
 * `prices` and `specs` use replace-all semantics: present → all existing
 * rows for the product are deleted and the submitted rows inserted in a
 * single transaction. Absent → existing rows untouched. Empty array →
 * all rows cleared (the product must be quote-only).
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'UpdateProductRequest',
    required: ['category_id', 'name'],
    properties: [
        new OA\Property(property: 'category_id', type: 'integer'),
        new OA\Property(
            property: 'name',
            type: 'object',
            required: ['en'],
            properties: [
                new OA\Property(property: 'en', type: 'string', minLength: 2, maxLength: 200),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
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
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_featured', type: 'boolean'),
        new OA\Property(property: 'is_quote_only', type: 'boolean'),
        new OA\Property(property: 'is_machine_eligible', type: 'boolean'),
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
                    new OA\Property(property: 'amount', type: 'number', format: 'float'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR']),
                    new OA\Property(property: 'period', type: 'string', enum: ['monthly', 'yearly'], nullable: true),
                    new OA\Property(property: 'min_term_months', type: 'integer', minimum: 1, maximum: 120, nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ],
            ),
        ),
        new OA\Property(
            property: 'specs',
            type: 'array',
            maxItems: 50,
            items: new OA\Items(
                type: 'object',
                required: ['label', 'value'],
                properties: [
                    new OA\Property(property: 'label', type: 'object'),
                    new OA\Property(property: 'value', type: 'object'),
                    new OA\Property(property: 'display_order', type: 'integer'),
                ],
            ),
        ),
    ],
    type: 'object',
)]
final class UpdateProductRequest
{
    // Documentation-only.
}
