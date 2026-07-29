<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Users\CreateAdminCustomerRequest;
use App\Http\Requests\Api\V1\Admin\Users\ListAdminUsersRequest;
use App\Http\Requests\Api\V1\Admin\Users\UpdateAdminUserRequest;
use App\Http\Resources\Api\V1\Admin\Users\AdminUserDetailResource;
use App\Http\Resources\Api\V1\Admin\Users\AdminUserListResource;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Admin\AdminUserService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side users lifecycle controller.
 *
 * Thin per CLAUDE.md § 15 — validation lives in Form Requests, business
 * logic in {@see AdminUserService}, authorization in
 * {@see UserPolicy}.
 *
 * Route-model binding for `{user}` resolves through Eloquent implicit
 * binding. The detail / update / destroy endpoints accept soft-deleted
 * rows by delegating the load to `AdminUserService::find($id)` which
 * uses `withTrashed()`. We therefore bind by id (integer) and let the
 * service do the lookup.
 *
 * See docs/architecture/admin-users.md.
 */
final class AdminUsersController extends Controller
{
    public function __construct(
        private readonly AdminUserService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/users',
        operationId: 'adminUsersIndex',
        description: 'Paginated list of all users (admins + customers). Filters: search (name/email/phone), role, is_active, include_trashed (super_admin only), created_from, created_to. Sortable by created_at, last_login_at, name, email.',
        summary: 'List users',
        security: [['sanctum' => []]],
        tags: ['Admin / Users'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 160)),
            new OA\Parameter(name: 'role', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['super_admin', 'operations', 'sales', 'support', 'customer'])),
            new OA\Parameter(name: 'is_active', in: 'query', required: false,
                schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'type', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['individual', 'company'])),
            new OA\Parameter(name: 'country', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2, example: 'AE')),
            new OA\Parameter(name: 'emirate', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\Parameter(name: 'include_trashed', in: 'query', required: false,
                description: 'When true, soft-deleted users are included. Requires the users.deactivate permission (super_admin).',
                schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'created_from', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'created_to', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['created_at', 'last_login_at', 'name', 'email'], default: 'created_at')),
            new OA\Parameter(name: 'order', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminUserListResponse')),
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
    public function index(ListAdminUsersRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $request->validated();
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);
        if ($includeTrashed && ! $actor->can('users.deactivate')) {
            throw new AuthorizationException(__('admin.users.trashed_forbidden'));
        }

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminUserListResource::collection($paginator),
            __('messages.ok'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/users',
        operationId: 'adminUsersStore',
        description: 'Create a new user account directly (no OTP). Defaults to customer role; admin roles require super_admin privileges. An optional address object can be supplied to simultaneously create the user\'s first address in their address book.',
        summary: 'Create user',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateAdminCustomerRequest'),
        ),
        tags: ['Admin / Users'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminUserDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateAdminCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $user = $this->service->createCustomer($request->validated(), $actor, $request->ip());

        return ApiResponse::success(
            (new AdminUserDetailResource($user))->resolve($request),
            __('admin.users.created'),
            201,
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/technicians',
        operationId: 'adminTechniciansIndex',
        description: 'List active technicians (users with operations role) for dropdowns in installation scheduling and maintenance assignment.',
        summary: 'List technicians',
        security: [['sanctum' => []]],
        tags: ['Admin / Users'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminTechnicianListResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function technicians(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $technicians = $this->service->listTechnicians();

        return ApiResponse::success(
            $technicians,
            __('messages.ok'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/users/{user}',
        operationId: 'adminUsersShow',
        description: 'Single-user detail. Soft-deleted rows are included so the dashboard can render a "deactivated" badge.',
        summary: 'Show user',
        security: [['sanctum' => []]],
        tags: ['Admin / Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 42)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminUserDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, int $user): JsonResponse
    {
        $target = $this->service->find($user);
        $this->authorize('viewAdmin', $target);

        return ApiResponse::success(
            (new AdminUserDetailResource($target))->resolve($request),
            __('messages.ok'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/users/{user}',
        operationId: 'adminUsersUpdate',
        description: 'Partial update. At least one mutable field must be present. Role changes require the caller to be super_admin (403 ERR_FORBIDDEN otherwise). Self-deactivation, self-role-change, and last-super-admin removal return 422.',
        summary: 'Update user',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAdminUserRequest'),
        ),
        tags: ['Admin / Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 42)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminUserDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (incl. role-change without super_admin)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed / cannot-deactivate-self / cannot-change-own-role / last-super-admin',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdateAdminUserRequest $request, int $user): JsonResponse
    {
        $target = $this->service->find($user);
        $this->authorize('updateAdmin', $target);

        $payload = $request->validated();
        if (array_key_exists('role', $payload)) {
            $this->authorize('changeRole', $target);
        }

        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->service->update($target, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminUserDetailResource($updated))->resolve($request),
            __('admin.users.updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/admin/users/{user}',
        operationId: 'adminUsersDestroy',
        description: 'Soft-deactivate the user: sets is_active=false, soft-deletes the row, revokes all the target\'s Sanctum tokens. Idempotent: a re-DELETE on an already-deactivated user returns 200 with the existing row. Self-deactivation and last-super-admin removal return 422.',
        summary: 'Deactivate user',
        security: [['sanctum' => []]],
        tags: ['Admin / Users'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 42)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deactivated',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminUserDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Cannot deactivate self / last super admin',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, int $user): JsonResponse
    {
        $target = $this->service->find($user);
        $this->authorize('deactivate', $target);

        /** @var User $actor */
        $actor = $request->user();
        $deactivated = $this->service->deactivate($target, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminUserDetailResource($deactivated))->resolve($request),
            __('admin.users.deactivated'),
        );
    }
}
