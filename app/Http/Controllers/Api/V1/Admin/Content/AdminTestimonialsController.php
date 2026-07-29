<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\CreateTestimonialRequest;
use App\Http\Requests\Api\V1\Admin\Content\ListAdminTestimonialsRequest;
use App\Http\Requests\Api\V1\Admin\Content\UpdateTestimonialRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminTestimonialDetailResource;
use App\Http\Resources\Api\V1\Admin\Content\AdminTestimonialResource;
use App\Models\Testimonial;
use App\Models\User;
use App\Policies\TestimonialPolicy;
use App\Services\Admin\AdminTestimonialService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side testimonials controller.
 *
 * Thin per CLAUDE.md § 15 — validation in Form Requests, business logic
 * in {@see AdminTestimonialService}, authorisation through
 * {@see TestimonialPolicy}.
 *
 * See docs/architecture/admin-content.md § 1.
 */
final class AdminTestimonialsController extends Controller
{
    public function __construct(
        private readonly AdminTestimonialService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/testimonials',
        operationId: 'adminTestimonialsIndex',
        description: 'Paginated testimonials across all is_active states (incl. trashed). Filters: search, is_active, rating, rating_min, rating_max, include_trashed.',
        summary: 'List testimonials',
        security: [['sanctum' => []]],
        tags: ['Admin / Testimonials'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 160)),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'rating', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 5)),
            new OA\Parameter(name: 'rating_min', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 5)),
            new OA\Parameter(name: 'rating_max', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 5)),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['display_order', 'rating', 'created_at'], default: 'display_order')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminTestimonialCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAdminTestimonialsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Testimonial::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminTestimonialResource::collection($paginator),
            __('admin.testimonials.retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/testimonials/{testimonial}',
        operationId: 'adminTestimonialsShow',
        description: 'Detail incl. raw bilingual JSON for the `quote` field. Soft-deleted rows visible.',
        summary: 'Show testimonial',
        security: [['sanctum' => []]],
        tags: ['Admin / Testimonials'],
        parameters: [
            new OA\Parameter(name: 'testimonial', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminTestimonialResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorize('view', $testimonial);

        return ApiResponse::success(
            (new AdminTestimonialDetailResource($testimonial))->resolve($request),
            __('admin.testimonials.show_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/testimonials',
        operationId: 'adminTestimonialsStore',
        description: 'Create a testimonial. Only `quote` is translatable; `name`, `role`, `company` are plain strings.',
        summary: 'Create testimonial',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateTestimonialRequest')),
        tags: ['Admin / Testimonials'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminTestimonialResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateTestimonialRequest $request): JsonResponse
    {
        $this->authorize('create', Testimonial::class);

        /** @var User $actor */
        $actor = $request->user();
        $testimonial = $this->service->create($request->validated(), $actor);

        return ApiResponse::success(
            (new AdminTestimonialDetailResource($testimonial))->resolve($request),
            __('admin.testimonials.created'),
            201,
        );
    }

    #[OA\Put(
        path: '/api/v1/admin/testimonials/{testimonial}',
        operationId: 'adminTestimonialsUpdate',
        description: 'Replace testimonial fields. `quote` is merged with stored translations so a single-locale PUT does not erase the other.',
        summary: 'Update testimonial',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateTestimonialRequest')),
        tags: ['Admin / Testimonials'],
        parameters: [
            new OA\Parameter(name: 'testimonial', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminTestimonialResponse')),
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
    public function update(UpdateTestimonialRequest $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorize('update', $testimonial);

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->update($testimonial, $request->validated(), $actor);

        return ApiResponse::success(
            (new AdminTestimonialDetailResource($updated))->resolve($request),
            __('admin.testimonials.updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/admin/testimonials/{testimonial}',
        operationId: 'adminTestimonialsDestroy',
        description: 'Soft-delete the testimonial. Super_admin only.',
        summary: 'Delete testimonial',
        security: [['sanctum' => []]],
        tags: ['Admin / Testimonials'],
        parameters: [
            new OA\Parameter(name: 'testimonial', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminTestimonialDeleteResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (super_admin only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, Testimonial $testimonial): JsonResponse
    {
        $this->authorize('delete', $testimonial);

        /** @var User $actor */
        $actor = $request->user();
        $deleted = $this->service->delete($testimonial, $actor);

        return ApiResponse::success(
            (new AdminTestimonialDetailResource($deleted))->resolve($request),
            __('admin.testimonials.deleted'),
        );
    }
}
