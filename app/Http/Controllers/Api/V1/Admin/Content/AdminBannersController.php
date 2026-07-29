<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\CreateBannerRequest;
use App\Http\Requests\Api\V1\Admin\Content\ListAdminBannersRequest;
use App\Http\Requests\Api\V1\Admin\Content\UpdateBannerRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerDetailResource;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerResource;
use App\Models\Banner;
use App\Models\User;
use App\Policies\BannerPolicy;
use App\Services\Admin\AdminBannerService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side banners controller.
 *
 * Thin per CLAUDE.md § 15 — validation in Form Requests, business logic
 * in {@see AdminBannerService}, authorisation through {@see BannerPolicy}.
 *
 * See docs/architecture/admin-content.md § 1.
 */
final class AdminBannersController extends Controller
{
    public function __construct(
        private readonly AdminBannerService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/banners',
        operationId: 'adminBannersIndex',
        description: 'Paginated banners across all is_active states (incl. scheduled, expired, and trashed). Filters: search, placement, is_active, starts_before/after, ends_before/after, include_trashed.',
        summary: 'List banners',
        security: [['sanctum' => []]],
        tags: ['Admin / Banners'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 160)),
            new OA\Parameter(name: 'placement', in: 'query', schema: new OA\Schema(type: 'string', enum: ['hero', 'promo', 'sidebar', 'footer'])),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'starts_before', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'starts_after', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'ends_before', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'ends_after', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['display_order', 'starts_at', 'ends_at', 'created_at'], default: 'display_order')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminBannerCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAdminBannersRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Banner::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminBannerResource::collection($paginator),
            __('admin.banners.retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/banners/{banner}',
        operationId: 'adminBannersShow',
        description: 'Detail incl. raw bilingual JSON for translatable fields. Soft-deleted rows are visible with deleted_at populated.',
        summary: 'Show banner',
        security: [['sanctum' => []]],
        tags: ['Admin / Banners'],
        parameters: [
            new OA\Parameter(name: 'banner', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminBannerResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, Banner $banner): JsonResponse
    {
        $this->authorize('view', $banner);

        return ApiResponse::success(
            (new AdminBannerDetailResource($banner))->resolve($request),
            __('admin.banners.show_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/banners',
        operationId: 'adminBannersStore',
        description: 'Create a banner. Returns 201 + full detail.',
        summary: 'Create banner',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateBannerRequest')),
        tags: ['Admin / Banners'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminBannerResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateBannerRequest $request): JsonResponse
    {
        $this->authorize('create', Banner::class);

        /** @var User $actor */
        $actor = $request->user();
        $banner = $this->service->create($request->validated(), $actor);

        return ApiResponse::success(
            (new AdminBannerDetailResource($banner))->resolve($request),
            __('admin.banners.created'),
            201,
        );
    }

    #[OA\Put(
        path: '/api/v1/admin/banners/{banner}',
        operationId: 'adminBannersUpdate',
        description: 'Replace the banner fields. `code` is immutable post-creation and is silently dropped if supplied.',
        summary: 'Update banner',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateBannerRequest')),
        tags: ['Admin / Banners'],
        parameters: [
            new OA\Parameter(name: 'banner', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminBannerResponse')),
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
    public function update(UpdateBannerRequest $request, Banner $banner): JsonResponse
    {
        $this->authorize('update', $banner);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->update($banner, $request->validated(), $actor);

        return ApiResponse::success(
            (new AdminBannerDetailResource($updated))->resolve($request),
            __('admin.banners.updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/admin/banners/{banner}',
        operationId: 'adminBannersDestroy',
        description: 'Soft-delete the banner. Super_admin only.',
        summary: 'Delete banner',
        security: [['sanctum' => []]],
        tags: ['Admin / Banners'],
        parameters: [
            new OA\Parameter(name: 'banner', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminBannerDeleteResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (super_admin only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, Banner $banner): JsonResponse
    {
        $this->authorize('delete', $banner);

        /** @var User $actor */
        $actor = $request->user();
        $deleted = $this->service->delete($banner, $actor);

        return ApiResponse::success(
            (new AdminBannerDetailResource($deleted))->resolve($request),
            __('admin.banners.deleted'),
        );
    }
}
