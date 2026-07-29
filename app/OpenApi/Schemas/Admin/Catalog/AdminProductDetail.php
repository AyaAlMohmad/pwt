<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * Full admin shape for a Product row — used both as the list item and
 * the single-resource detail body.
 *
 * Raw bilingual JSON for every translatable field so the admin form can
 * hydrate both locales in one fetch.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminProductDetail',
    required: [
        'id', 'code', 'name', 'category_id', 'is_active', 'is_featured',
        'is_quote_only', 'is_machine_eligible', 'stock_status',
        'display_order', 'created_at',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 17),
        new OA\Property(property: 'code', type: 'string', maxLength: 80, example: 'PW100M'),
        new OA\Property(
            property: 'name',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string', example: 'PW100M Counter-top Filter'),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'short_description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'description',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'string', nullable: true),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'benefits',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'en', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'ar', type: 'array', items: new OA\Items(type: 'string')),
            ],
        ),
        new OA\Property(property: 'category_id', type: 'integer', example: 1),
        new OA\Property(property: 'category', ref: '#/components/schemas/AdminCategoryDetail', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_featured', type: 'boolean'),
        new OA\Property(property: 'is_quote_only', type: 'boolean'),
        new OA\Property(property: 'is_machine_eligible', type: 'boolean'),
        new OA\Property(property: 'stock_status', type: 'string', enum: ['in_stock', 'out_of_stock', 'coming_soon', 'discontinued']),
        new OA\Property(property: 'display_order', type: 'integer'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'images', type: 'array', items: new OA\Items(ref: '#/components/schemas/AdminProductImage')),
        new OA\Property(property: 'specs', type: 'array', items: new OA\Items(ref: '#/components/schemas/AdminProductSpec')),
        new OA\Property(property: 'prices', type: 'array', items: new OA\Items(ref: '#/components/schemas/AdminProductPrice')),
        new OA\Property(property: 'orders_count', type: 'integer', example: 0, description: 'Count of non-terminal orders referencing this product.'),
        new OA\Property(property: 'images_count', type: 'integer', example: 0),
    ],
    type: 'object',
)]
final class AdminProductDetail
{
    // Documentation-only.
}
