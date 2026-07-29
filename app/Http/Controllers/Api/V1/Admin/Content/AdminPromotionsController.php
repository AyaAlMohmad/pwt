<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\CreatePromotionRequest;
use App\Http\Requests\Api\V1\Admin\Content\ListAdminPromotionsRequest;
use App\Http\Requests\Api\V1\Admin\Content\UpdatePromotionRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminPromotionDetailResource;
use App\Http\Resources\Api\V1\Admin\Content\AdminPromotionResource;
use App\Models\Promotion;
use App\Models\User;
use App\Policies\PromotionPolicy;
use App\Services\Admin\AdminPromotionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side promotions controller.
 *
 * Thin per CLAUDE.md § 15 — validation in Form Requests, business logic
 * in {@see AdminPromotionService}, authorisation through
 * {@see PromotionPolicy}.
 *
 * DELETE blocks with 422 ERR_PROMOTION_HAS_REDEMPTIONS when the promotion
 * has any rows in `promotion_redemptions`.
 *
 * See docs/architecture/admin-content.md § 1.
 */
final class AdminPromotionsController extends Controller
{
    public function __construct(
        private readonly AdminPromotionService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/promotions',
        operationId: 'adminPromotionsIndex',
        description: 'Paginated promotions across all is_active states + scheduled + expired + trashed. Filters: search, type, applies_to, is_active, status (active|upcoming|expired|inactive), starts_before/after, ends_before/after, include_trashed.',
        summary: 'List promotions',
        security: [['sanctum' => []]],
        tags: ['Admin / Promotions'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 160)),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['percent', 'fixed'])),
            new OA\Parameter(name: 'applies_to', in: 'query', schema: new OA\Schema(type: 'string', enum: ['all', 'products', 'categories'])),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['upcoming', 'active', 'expired', 'inactive'])),
            new OA\Parameter(name: 'starts_before', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'starts_after', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'ends_before', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'ends_after', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['code', 'starts_at', 'ends_at', 'created_at'], default: 'starts_at')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminPromotionCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAdminPromotionsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Promotion::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminPromotionResource::collection($paginator),
            __('admin.promotions.retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/promotions/{promotion}',
        operationId: 'adminPromotionsShow',
        description: 'Detail incl. raw bilingual JSON for name/description, the full applies_to pivot rows, redemption aggregates, and total_limit / per_user_limit. Soft-deleted rows visible.',
        summary: 'Show promotion',
        security: [['sanctum' => []]],
        tags: ['Admin / Promotions'],
        parameters: [
            new OA\Parameter(name: 'promotion', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminPromotionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorize('view', $promotion);

        // Re-load with detail eager-loads + aggregates.
        $detailed = $this->service->find((int) $promotion->id);

        return ApiResponse::success(
            (new AdminPromotionDetailResource($detailed))->resolve($request),
            __('admin.promotions.show_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/promotions',
        operationId: 'adminPromotionsStore',
        description: 'Create a promotion. `code` is uppercased + trimmed server-side. When applies_to=products|categories the matching pivot is replace-all inserted inside the same transaction.',
        summary: 'Create promotion',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreatePromotionRequest')),
        tags: ['Admin / Promotions'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminPromotionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreatePromotionRequest $request): JsonResponse
    {
        $this->authorize('create', Promotion::class);

        /** @var User $actor */
        $actor = $request->user();
        $promotion = $this->service->create($request->validated(), $actor);

        return ApiResponse::success(
            (new AdminPromotionDetailResource($promotion))->resolve($request),
            __('admin.promotions.created'),
            201,
        );
    }

    #[OA\Put(
        path: '/api/v1/admin/promotions/{promotion}',
        operationId: 'adminPromotionsUpdate',
        description: 'Replace promotion fields. `code` is immutable and silently dropped if supplied. Pivot tables (`promotion_products` / `promotion_categories`) use replace-all semantics when applies_to / product_ids / category_ids is present.',
        summary: 'Update promotion',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePromotionRequest')),
        tags: ['Admin / Promotions'],
        parameters: [
            new OA\Parameter(name: 'promotion', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminPromotionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $this->authorize('update', $promotion);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->update($promotion, $request->validated(), $actor);

        return ApiResponse::success(
            (new AdminPromotionDetailResource($updated))->resolve($request),
            __('admin.promotions.updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/admin/promotions/{promotion}',
        operationId: 'adminPromotionsDestroy',
        description: 'Soft-delete the promotion. Returns 422 ERR_PROMOTION_HAS_REDEMPTIONS when any redemption row exists — admins should deactivate via PUT is_active=false instead. Super_admin only.',
        summary: 'Delete promotion',
        security: [['sanctum' => []]],
        tags: ['Admin / Promotions'],
        parameters: [
            new OA\Parameter(name: 'promotion', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminPromotionDeleteResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (super_admin only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Promotion has redemptions',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorize('delete', $promotion);

        /** @var User $actor */
        $actor = $request->user();
        $deleted = $this->service->delete($promotion, $actor);

        return ApiResponse::success(
            (new AdminPromotionDetailResource($deleted))->resolve($request),
            __('admin.promotions.deleted'),
        );
    }
}
