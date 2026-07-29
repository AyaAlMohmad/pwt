<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\CreateCategoryRequest;
use App\Http\Requests\Api\V1\Admin\Catalog\ListAdminCategoriesRequest;
use App\Http\Requests\Api\V1\Admin\Catalog\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminCategoryResource;
use App\Models\Category;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Services\Admin\AdminCategoryService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side categories controller.
 *
 * Thin per CLAUDE.md § 15 — validation lives in Form Requests, business
 * logic in {@see AdminCategoryService}, authorization in
 * {@see CategoryPolicy}.
 *
 * DELETE is super_admin-only at the policy layer.
 *
 * See docs/architecture/admin-catalog.md.
 */
final class AdminCategoriesController extends Controller
{
    public function __construct(
        private readonly AdminCategoryService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/categories',
        operationId: 'adminCategoriesIndex',
        description: 'Paginated list of all categories including inactive (and optionally soft-deleted). Filters: search, is_active, parent_id, include_trashed. Sortable by display_order, name, code, created_at.',
        summary: 'List categories',
        security: [['sanctum' => []]],
        tags: ['Admin / Categories'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'parent_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['display_order', 'name', 'code', 'created_at'], default: 'display_order')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminCategoryListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAdminCategoriesRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminCategoryResource::collection($paginator),
            __('messages.ok'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/categories/{category}',
        operationId: 'adminCategoriesShow',
        description: 'Single-category detail. Soft-deleted rows are included so the dashboard can render a "deactivated" badge.',
        summary: 'Show category',
        security: [['sanctum' => []]],
        tags: ['Admin / Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminCategoryDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, int $category): JsonResponse
    {
        $target = $this->service->find($category);
        $this->authorize('view', $target);

        return ApiResponse::success(
            (new AdminCategoryResource($target))->resolve($request),
            __('messages.ok'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/categories',
        operationId: 'adminCategoriesStore',
        description: 'Create a new category. Bilingual name/description objects. EN is mandatory; AR optional.',
        summary: 'Create category',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateCategoryRequest'),
        ),
        tags: ['Admin / Categories'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminCategoryDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        /** @var User $actor */
        $actor = $request->user();
        $category = $this->service->create($request->validated(), $actor);

        return ApiResponse::success(
            (new AdminCategoryResource($category))->resolve($request),
            __('admin.catalog.category_created'),
            201,
        );
    }

    #[OA\Put(
        path: '/api/v1/admin/categories/{category}',
        operationId: 'adminCategoriesUpdate',
        description: 'Replace the category fields. PUT semantics — every translatable field must be re-sent.',
        summary: 'Update category',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCategoryRequest'),
        ),
        tags: ['Admin / Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminCategoryDetailResponse')),
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
    public function update(UpdateCategoryRequest $request, int $category): JsonResponse
    {
        $target = $this->service->find($category);
        $this->authorize('update', $target);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->update($target, $request->validated(), $actor);

        return ApiResponse::success(
            (new AdminCategoryResource($updated))->resolve($request),
            __('admin.catalog.category_updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/admin/categories/{category}',
        operationId: 'adminCategoriesDestroy',
        description: 'Soft-delete the category. Blocked with 422 ERR_CATEGORY_HAS_PRODUCTS when the category still has non-trashed products. Super_admin only.',
        summary: 'Delete category',
        security: [['sanctum' => []]],
        tags: ['Admin / Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminCategoryDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (super_admin only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Category has products',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, int $category): JsonResponse
    {
        $target = $this->service->find($category);
        $this->authorize('delete', $target);

        /** @var User $actor */
        $actor = $request->user();
        $deactivated = $this->service->deactivate($target, $actor);

        return ApiResponse::success(
            (new AdminCategoryResource($deactivated))->resolve($request),
            __('admin.catalog.category_deleted'),
        );
    }
}
