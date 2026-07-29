<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Promotions;

use App\Enums\BannerPlacement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Promotions\ListBannersRequest;
use App\Http\Resources\Api\V1\Promotions\BannerResource;
use App\Models\Banner;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Public banners endpoint.
 *
 * Mounted at `/api/v1/banners`. Guest-accessible, on the `public` rate
 * limiter (60/min/IP per CLAUDE.md § 5.7). Always returns only
 * currently-active rows — admin-side visibility is Phase 20.
 */
final class BannersController extends Controller
{
    #[OA\Get(
        path: '/api/v1/banners',
        operationId: 'promotionsBannersIndex',
        description: 'List active banners visible to customers. Optional `?placement=hero|promo|sidebar|footer` filter. Sorted by display_order then id. Unpaginated — bounded resource (≤ ~20 rows total). Rate limited at 60 requests per minute per IP.',
        summary: 'List active banners',
        tags: ['Banners'],
        parameters: [
            new OA\Parameter(name: 'placement', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['hero', 'promo', 'sidebar', 'footer'])),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/BannerCollectionResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListBannersRequest $request): JsonResponse
    {
        $placementValue = $request->validated('placement');
        $placement = is_string($placementValue) && $placementValue !== ''
            ? BannerPlacement::tryFrom($placementValue)
            : null;

        $banners = Banner::query()
            ->active()
            ->forPlacement($placement)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            BannerResource::collection($banners)->resolve($request),
            __('promotions.banners_retrieved'),
        );
    }
}
