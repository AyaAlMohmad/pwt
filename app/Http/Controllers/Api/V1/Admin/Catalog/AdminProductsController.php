<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Catalog\AttachProductImageRequest;
use App\Http\Requests\Api\V1\Admin\Catalog\CreateProductRequest;
use App\Http\Requests\Api\V1\Admin\Catalog\ListAdminProductsRequest;
use App\Http\Requests\Api\V1\Admin\Catalog\UpdateProductRequest;
use App\Http\Requests\Api\V1\Admin\Catalog\UpdateProductStatusRequest;
use App\Http\Resources\Api\V1\Admin\Catalog\AdminProductDetailResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\Admin\AdminProductService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin-side products controller.
 *
 * Owns 8 endpoints: the 5 standard apiResource actions plus
 * `updateStatus`, `attachImage`, `detachImage`. Each method is ≤ 30 LOC
 * per CLAUDE.md § 15.
 *
 * Authorization is delegated to {@see ProductPolicy} via
 * `$this->authorize(...)`. The DELETE method is super_admin-only at the
 * policy layer.
 *
 * See docs/architecture/admin-catalog.md.
 */
final class AdminProductsController extends Controller
{
    public function __construct(
        private readonly AdminProductService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/products',
        operationId: 'adminProductsIndex',
        description: 'Paginated product list across all is_active states (and optionally soft-deleted). Filters: search, category_id, is_active, is_featured, is_quote_only, stock_status, term, created_from, created_to, include_trashed.',
        summary: 'List products',
        security: [['sanctum' => []]],
        tags: ['Admin / Products'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 160)),
            new OA\Parameter(name: 'category_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'is_featured', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'is_quote_only', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'stock_status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['in_stock', 'out_of_stock', 'coming_soon', 'discontinued'])),
            new OA\Parameter(name: 'term', in: 'query', schema: new OA\Schema(type: 'string', enum: ['buy', 'rent', 'foc'])),
            new OA\Parameter(name: 'created_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'created_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'name', 'code', 'display_order'], default: 'created_at')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAdminProductsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminProductDetailResource::collection($paginator),
            __('messages.ok'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/products/{product}',
        operationId: 'adminProductsShow',
        description: 'Full product detail including ALL price rows (active + inactive), specs, images, category, and counts. Soft-deleted products are returned with `deleted_at` populated.',
        summary: 'Show product',
        security: [['sanctum' => []]],
        tags: ['Admin / Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, int $product): JsonResponse
    {
        $target = $this->service->find($product);
        $this->authorize('view', $target);

        return ApiResponse::success(
            (new AdminProductDetailResource($target))->resolve($request),
            __('messages.ok'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/products',
        operationId: 'adminProductsStore',
        description: 'Create a product. Optionally accepts inline `prices: [...]` and `specs: [...]` arrays inserted in the same transaction. `images: [...]` is rejected — use POST /admin/products/{id}/images.',
        summary: 'Create product',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateProductRequest'),
        ),
        tags: ['Admin / Products'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        /** @var User $actor */
        $actor = $request->user();
        $product = $this->service->create($request->validated(), $actor);

        return ApiResponse::success(
            (new AdminProductDetailResource($product))->resolve($request),
            __('admin.catalog.product_created'),
            201,
        );
    }

    #[OA\Put(
        path: '/api/v1/admin/products/{product}',
        operationId: 'adminProductsUpdate',
        description: 'Replace the product fields. `code` is immutable (422 ERR_PRODUCT_CODE_IMMUTABLE if present). When `prices` or `specs` are submitted, replace-all semantics apply atomically.',
        summary: 'Update product',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateProductRequest'),
        ),
        tags: ['Admin / Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation / code immutable',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdateProductRequest $request, int $product): JsonResponse
    {
        $target = $this->service->find($product);
        $this->authorize('update', $target);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->update($target, $request->validated(), $actor);

        return ApiResponse::success(
            (new AdminProductDetailResource($updated))->resolve($request),
            __('admin.catalog.product_updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/admin/products/{product}',
        operationId: 'adminProductsDestroy',
        description: 'Soft-delete the product. Blocked with 422 ERR_PRODUCT_HAS_ACTIVE_ORDERS when the product is referenced by an open (pending/confirmed/scheduled/out_for_delivery) order. Super_admin only.',
        summary: 'Delete product',
        security: [['sanctum' => []]],
        tags: ['Admin / Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (super_admin only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Product has active orders',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, int $product): JsonResponse
    {
        $target = $this->service->find($product);
        $this->authorize('delete', $target);

        /** @var User $actor */
        $actor = $request->user();
        $deactivated = $this->service->deactivate($target, $actor);

        return ApiResponse::success(
            (new AdminProductDetailResource($deactivated))->resolve($request),
            __('admin.catalog.product_deleted'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/products/{product}/status',
        operationId: 'adminProductsStatusUpdate',
        description: 'Thin toggle endpoint — accepts any subset of `is_active`, `is_featured`, `is_quote_only`, `stock_status`. At least one must be present. Returns full product detail.',
        summary: 'Update product status',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateProductStatusRequest'),
        ),
        tags: ['Admin / Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductDetailResponse')),
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
    public function updateStatus(UpdateProductStatusRequest $request, int $id): JsonResponse
    {
        $target = $this->service->find($id);
        $this->authorize('updateStatus', $target);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->updateStatus($target, $request->validated(), $actor);

        return ApiResponse::success(
            (new AdminProductDetailResource($updated))->resolve($request),
            __('admin.catalog.product_status_updated'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/products/{product}/images',
        operationId: 'adminProductImagesStore',
        description: 'Attach pre-uploaded files rows (type=product_image) as product images. Accepts an array of file_ids. Idempotent on (product_id, file_id). When is_primary=true, the previous primary is atomically demoted and the FIRST attached image becomes primary. Returns full product detail with the refreshed images array.',
        summary: 'Attach product image',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AttachProductImageRequest'),
        ),
        tags: ['Admin / Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductDetailResponse')),
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
    public function attachImage(AttachProductImageRequest $request, int $id): JsonResponse
    {
        $target = $this->service->find($id);
        $this->authorize('manageImages', $target);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->attachImage($target, $request->validated(), $actor);

        return ApiResponse::success(
            (new AdminProductDetailResource($updated))->resolve($request),
            __('admin.catalog.image_attached'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/admin/products/{productId}/images/{imageId}',
        operationId: 'adminProductImagesDestroy',
        description: 'Hard-delete a product image row. Cross-product mismatch (image belongs to a different product) returns 404. Returns full product detail.',
        summary: 'Detach product image',
        security: [['sanctum' => []]],
        tags: ['Admin / Products'],
        parameters: [
            new OA\Parameter(name: 'productId', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
            new OA\Parameter(name: 'imageId', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminProductDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found / cross-product mismatch',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function detachImage(Request $request, int $productId, int $imageId): JsonResponse
    {
        $target = $this->service->find($productId);
        $this->authorize('manageImages', $target);

        /** @var ProductImage|null $image */
        $image = ProductImage::query()->find($imageId);
        if ($image === null || (int) $image->product_id !== (int) $target->id) {
            throw new NotFoundHttpException(__('messages.not_found'));
        }

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->detachImage($target, $image, $actor);

        return ApiResponse::success(
            (new AdminProductDetailResource($updated))->resolve($request),
            __('admin.catalog.image_detached'),
        );
    }
}
