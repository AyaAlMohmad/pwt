<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\ListCategoriesRequest;
use App\Http\Requests\Api\V1\Catalog\ListProductsRequest;
use App\Http\Resources\Api\V1\Catalog\CategoryResource;
use App\Http\Resources\Api\V1\Catalog\ProductDetailResource;
use App\Http\Resources\Api\V1\Catalog\ProductResource;
use App\Services\Catalog\CatalogService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Public read-only catalog endpoints.
 *
 * Mounted under `/api/v1/categories` and `/api/v1/products`. All four
 * methods are guest-accessible per docs/architecture/catalog.md § 1 and
 * stay on the `public` rate limiter (60/min/IP — CLAUDE.md § 5.7).
 *
 * Each method is intentionally trivial (≤ 30 LOC per CLAUDE.md § 15):
 * validation lives in the Form Requests, query construction in
 * {@see CatalogService}, response shaping in the Resources.
 */
final class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogService $catalog,
    ) {}

    #[OA\Get(
        path: '/api/v1/categories',
        operationId: 'catalogCategoriesIndex',
        description: 'Returns all active, non-trashed categories sorted by display_order then the localized name. Unpaginated (bounded resource — see docs/architecture/catalog.md § 1.3). Rate limited at 60 requests per minute per IP.',
        summary: 'List active categories',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', maxLength: 100, example: 'filter')),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CategoryCollectionResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function categoriesIndex(ListCategoriesRequest $request): JsonResponse
    {
        $search = $request->validated('search');
        $categories = $this->catalog->listCategories(
            is_string($search) ? $search : null,
        );

        return ApiResponse::success(
            CategoryResource::collection($categories)->resolve($request),
            __('catalog.categories_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/categories/{id}',
        operationId: 'catalogCategoriesShow',
        description: 'Returns one active category with a `products_count` (count of active products in the category). Returns 404 for missing, soft-deleted, or inactive rows — inactive categories are invisible to customers (docs/architecture/catalog.md § 1.4). Rate limited at 60 requests per minute per IP.',
        summary: 'Show one category',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CategoryResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function categoryShow(int $id, Request $request): JsonResponse
    {
        $category = $this->catalog->findCategory($id);

        return ApiResponse::success(
            (new CategoryResource($category))->resolve($request),
            __('catalog.category_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/products',
        operationId: 'catalogProductsIndex',
        description: 'Paginated list of active products with category, primary image, and active-currency starting price eager-loaded. Filter by `category_id`, `term`, `search`, `min_price`, `max_price`, `currency`, `featured`. Sort by `price`, `created_at`, or `name`. Default per_page=15, max=100. Rate limited at 60 requests per minute per IP.',
        summary: 'List active products (paginated)',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'category_id', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'term', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['buy', 'rent', 'foc'])),
            new OA\Parameter(name: 'search', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'min_price', in: 'query', required: false,
                schema: new OA\Schema(type: 'number', format: 'float', minimum: 0)),
            new OA\Parameter(name: 'max_price', in: 'query', required: false,
                schema: new OA\Schema(type: 'number', format: 'float', minimum: 0)),
            new OA\Parameter(name: 'currency', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR'], default: 'AED')),
            new OA\Parameter(name: 'sort', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['price', 'created_at', 'name'], default: 'created_at')),
            new OA\Parameter(name: 'order', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'featured', in: 'query', required: false,
                schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/ProductCollectionResponse')),
            new OA\Response(response: 422, description: 'Validation failed (any invalid filter)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function productsIndex(ListProductsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $currency = $this->catalog->resolveCurrency($filters);

        // Surface the resolved currency + active term on the request so
        // resources can pick the right starting_price without re-parsing
        // the input (docs/architecture/catalog.md § 4.4). Both the
        // FormRequest AND the underlying container-bound Request must
        // receive the attributes: JsonResource::jsonSerialize() pulls
        // the Request from the container, not from this method's args.
        $base = app('request');
        foreach ([$request, $base] as $req) {
            $req->attributes->set('catalog.currency', $currency);
            if (isset($filters['term']) && is_string($filters['term'])) {
                $req->attributes->set('catalog.term', $filters['term']);
            }
        }

        $paginator = $this->catalog->listProducts($filters);

        return ApiResponse::success(
            ProductResource::collection($paginator),
            __('catalog.products_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/products/{id}',
        operationId: 'catalogProductsShow',
        description: 'Returns one active product with category, all images, all specs, and all active prices across currencies/terms. `promotions_count` is hardcoded to 0 until Phase 7 (Promotions) wires the real count. Returns 404 for missing, soft-deleted, or inactive rows. Rate limited at 60 requests per minute per IP.',
        summary: 'Show one product (full detail)',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 12)),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/ProductDetailResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function productShow(int $id, Request $request): JsonResponse
    {
        $product = $this->catalog->findProduct($id);

        return ApiResponse::success(
            (new ProductDetailResource($product))->resolve($request),
            __('catalog.product_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/products/best-sellers',
        operationId: 'catalogProductsBestSellers',
        description: 'Returns active products tagged as best-seller, ordered by display_order then most recently created. Eager-loads category, primary image, and active prices. Rate limited at 60 requests per minute per IP.',
        summary: 'List best-seller products',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'currency', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR'], default: 'AED')),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/ProductCollectionResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function bestSellers(Request $request): JsonResponse
    {
        $currency = strtoupper(
            is_string($request->query('currency')) && $request->query('currency') !== ''
                ? $request->query('currency')
                : 'AED'
        );

        $base = app('request');
        foreach ([$request, $base] as $req) {
            $req->attributes->set('catalog.currency', $currency);
        }

        $products = $this->catalog->bestSellers();

        return ApiResponse::success(
            ProductResource::collection($products)->resolve($request),
            __('catalog.best_sellers_retrieved'),
        );
    }
}
