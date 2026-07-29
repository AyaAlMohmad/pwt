<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Admin shape for a Testimonial row — used both as list item and single
 * detail.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminTestimonial',
    required: ['id', 'name', 'rating', 'quote', 'display_order', 'is_active', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120),
        new OA\Property(property: 'role', type: 'string', maxLength: 120, nullable: true),
        new OA\Property(property: 'company', type: 'string', maxLength: 120, nullable: true),
        new OA\Property(property: 'avatar_url', type: 'string', nullable: true),
        new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5),
        new OA\Property(
            property: 'quote',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string'),
                new OA\Property(property: 'ar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'display_order', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'trashed']),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
final class AdminTestimonial
{
    // Documentation-only.
}
