<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Promotions\TestimonialResource;
use App\Models\Testimonial;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Public testimonials endpoint.
 *
 * Mounted at `/api/v1/testimonials`. Guest-accessible, on the `public`
 * rate limiter. Unpaginated — bounded resource (homepage carousel).
 */
final class TestimonialsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/testimonials',
        operationId: 'promotionsTestimonialsIndex',
        description: 'List published testimonials. Sorted by display_order then id. Unpaginated — bounded resource. Only `quote` is translated to the active locale; `role` and `company` are plain strings in Phase 7. Rate limited at 60 requests per minute per IP.',
        summary: 'List active testimonials',
        tags: ['Testimonials'],
        parameters: [
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/TestimonialCollectionResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $testimonials = Testimonial::query()
            ->active()
            ->ordered()
            ->get();

        return ApiResponse::success(
            TestimonialResource::collection($testimonials)->resolve($request),
            __('promotions.testimonials_retrieved'),
        );
    }
}
