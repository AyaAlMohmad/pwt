<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * 200 / 201 OK envelope for single-testimonial admin endpoints.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminTestimonialResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminTestimonial'),
    ],
    type: 'object',
)]
final class AdminTestimonialResponse
{
    // Documentation-only.
}
