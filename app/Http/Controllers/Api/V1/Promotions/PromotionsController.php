<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Promotions\ListPromotionsRequest;
use App\Http\Requests\Api\V1\Promotions\ValidatePromotionRequest;
use App\Http\Resources\Api\V1\Promotions\PromotionResource;
use App\Http\Resources\Api\V1\Promotions\PromotionValidationResource;
use App\Models\User;
use App\Services\Promotions\PromotionEngine;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Promotions endpoints.
 *
 * - `GET /promotions`           — public, lists currently-active promos.
 * - `POST /promotions/validate` — authenticated, validates a code against
 *                                  a cart context. Always 200 (Stripe-
 *                                  style soft-fail; § 5.1).
 */
final class PromotionsController extends Controller
{
    public function __construct(
        private readonly PromotionEngine $engine,
    ) {}

    #[OA\Get(
        path: '/api/v1/promotions',
        operationId: 'promotionsIndex',
        description: 'List currently-active promotions visible to customers. Filtered server-side to `is_active=true` AND inside the active window. Sorted by `starts_at DESC, id DESC`. Unpaginated — bounded resource. Sensitive admin-only fields (`total_limit`, `per_user_limit`) are masked. Rate limited at 60 requests per minute per IP.',
        summary: 'List active promotions',
        tags: ['Promotions'],
        parameters: [
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/PromotionCollectionResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListPromotionsRequest $request): JsonResponse
    {
        $promotions = $this->engine->listActive();

        return ApiResponse::success(
            PromotionResource::collection($promotions)->resolve($request),
            __('promotions.promotions_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/promotions/validate',
        operationId: 'promotionsValidate',
        description: 'Validate a promo code against an order context. **Always returns HTTP 200** for promo-rule failures, with `data.valid=false` and a `reason_code` describing why (Stripe-style soft-fail — see docs/architecture/promotions.md § 5.1). HTTP 4xx is reserved for auth / validation / rate-limit failures. This endpoint is purely read-only — it does NOT reserve or redeem the code (Phase 8 writes the redemption row inside the order-creation transaction). Rate limited at 10 requests per minute per authenticated user to defeat brute-force code enumeration.',
        summary: 'Validate a promo code',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PromotionValidationRequest'),
        ),
        tags: ['Promotions'],
        parameters: [
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK — `data.valid` is true on a match, false with `reason_code` on a soft-fail',
                content: new OA\JsonContent(ref: '#/components/schemas/PromotionValidationResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function validate(ValidatePromotionRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $productIds = isset($data['product_ids']) && is_array($data['product_ids'])
            ? array_values(array_map(static fn ($id): int => (int) $id, $data['product_ids']))
            : [];
        $categoryIds = isset($data['category_ids']) && is_array($data['category_ids'])
            ? array_values(array_map(static fn ($id): int => (int) $id, $data['category_ids']))
            : [];

        $result = $this->engine->validate(
            code: (string) $data['code'],
            user: $user,
            subtotal: (float) $data['subtotal'],
            currency: (string) $data['currency'],
            productIds: $productIds,
            categoryIds: $categoryIds,
        );

        return ApiResponse::success(
            (new PromotionValidationResource($result))->resolve($request),
            __('promotions.validated'),
        );
    }
}
