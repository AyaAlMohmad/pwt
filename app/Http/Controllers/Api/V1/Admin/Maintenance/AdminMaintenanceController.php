<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Maintenance\AssignMaintenanceRequest;
use App\Http\Requests\Api\V1\Admin\Maintenance\CloseMaintenanceRequest;
use App\Http\Requests\Api\V1\Admin\Maintenance\ListAdminMaintenanceRequestsRequest;
use App\Http\Requests\Api\V1\Admin\Maintenance\SubmitOnBehalfMaintenanceRequest;
use App\Http\Requests\Api\V1\Admin\Maintenance\UpdateMaintenanceStatusRequest;
use App\Http\Resources\Api\V1\Admin\Maintenance\AdminMaintenanceRequestDetailResource;
use App\Http\Resources\Api\V1\Admin\Maintenance\AdminMaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Policies\MaintenanceRequestPolicy;
use App\Services\Admin\AdminMaintenanceService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side maintenance controller.
 *
 * Thin per CLAUDE.md § 15 — validation lives in Form Requests, business
 * logic in {@see AdminMaintenanceService}, authorization in
 * {@see MaintenanceRequestPolicy}. The state machine
 * itself still lives in Phase 10's MaintenanceRequestService.
 *
 * See docs/architecture/admin-machines-maintenance.md.
 */
final class AdminMaintenanceController extends Controller
{
    public function __construct(
        private readonly AdminMaintenanceService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/maintenance-requests',
        operationId: 'adminMaintenanceIndex',
        description: 'Paginated list across all maintenance requests. Filters: status (single or array), priority, issue_type, assigned_to_user_id (0 = unassigned), customer_id, machine_id, country_code, search, period (shorthand for date_from/to), date_from/to, include_trashed (super_admin).',
        summary: 'List maintenance requests (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Maintenance'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['new', 'assigned', 'in_progress', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'urgent'])),
            new OA\Parameter(name: 'issue_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'assigned_to_user_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'customer_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'machine_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'machine_ids', in: 'query', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'country_code', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 160)),
            new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string', enum: ['today', 'yesterday', 'last_7_days', 'this_week', 'this_month', 'last_month']),
                description: 'Convenience shorthand that auto-populates date_from and date_to.'),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'priority', 'status', 'preferred_date'], default: 'created_at')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMaintenanceCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAdminMaintenanceRequestsRequest $request): JsonResponse
    {
        $this->authorize('viewAnyAdmin', MaintenanceRequest::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->validated();

        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);
        if ($includeTrashed && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException(__('admin.maintenance.trashed_forbidden'));
        }

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminMaintenanceRequestResource::collection($paginator),
            __('admin.maintenance.retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/maintenance-requests/{id}',
        operationId: 'adminMaintenanceShow',
        description: 'Single-request detail including internal_notes (admin-only), full status history with actor identities, media with signed URLs.',
        summary: 'Show maintenance request (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMaintenanceDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $req = $this->service->find($id);
        $this->authorize('viewAdmin', $req);

        return ApiResponse::success(
            (new AdminMaintenanceRequestDetailResource($req))->resolve($request),
            __('admin.maintenance.show_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/maintenance-requests',
        operationId: 'adminMaintenanceStore',
        description: 'Submit on behalf of a customer. Admins may set high/urgent priority (no clamp). Fires MaintenanceRequestCreated event so Phase 11 notifies the customer. No media at submit — customer attaches later.',
        summary: 'Submit on behalf of customer (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreAdminMaintenanceRequest')),
        tags: ['Admin / Maintenance'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMaintenanceDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(SubmitOnBehalfMaintenanceRequest $request): JsonResponse
    {
        $this->authorize('createAdmin', MaintenanceRequest::class);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();

        // Batch creation: multiple machines in one request.
        if (isset($payload['machine_ids']) && is_array($payload['machine_ids'])) {
            $requests = $this->service->submitBatchOnBehalf($payload, $actor, $request->ip());

            return ApiResponse::success(
                AdminMaintenanceRequestDetailResource::collection(collect($requests))->resolve($request),
                __('admin.maintenance.created_batch'),
                201,
            );
        }

        $req = $this->service->submitOnBehalf($payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMaintenanceRequestDetailResource($req))->resolve($request),
            __('admin.maintenance.created'),
            201,
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/maintenance-requests/{id}/assign',
        operationId: 'adminMaintenanceAssign',
        description: 'Assign or re-assign a technician (operations admin). From `new`: transitions to `assigned`. From `assigned`: idempotent re-assignment with notes-only history row. Omitting assigned_to_user_id unassigns — an `assigned` request returns to `new`.',
        summary: 'Assign maintenance request (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AssignMaintenanceRequest')),
        tags: ['Admin / Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Assigned',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMaintenanceDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Invalid state transition',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function assign(AssignMaintenanceRequest $request, int $id): JsonResponse
    {
        $req = $this->service->find($id);
        $this->authorize('assignAdmin', $req);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $updated = $this->service->assign($req, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMaintenanceRequestDetailResource($updated))->resolve($request),
            __('admin.maintenance.assigned'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/maintenance-requests/{id}/status',
        operationId: 'adminMaintenanceUpdateStatus',
        description: 'Generic status transition. Body status=assigned -> 409 ERR_USE_ASSIGN_ENDPOINT. Body status=completed -> 409 ERR_USE_CLOSE_ENDPOINT. Cancellation requires a `reason`.',
        summary: 'Update maintenance status (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAdminMaintenanceStatusRequest')),
        tags: ['Admin / Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMaintenanceDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Invalid transition / use dedicated endpoint',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function updateStatus(UpdateMaintenanceStatusRequest $request, int $id): JsonResponse
    {
        $req = $this->service->find($id);
        $this->authorize('updateStatusAdmin', $req);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $updated = $this->service->transition($req, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMaintenanceRequestDetailResource($updated))->resolve($request),
            __('admin.maintenance.status_updated'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/maintenance-requests/{id}/close',
        operationId: 'adminMaintenanceClose',
        description: 'Close an in_progress request. Writes resolution_notes, inserts a machine_maintenance_logs row (source=request), recomputes the machine next_maintenance_due_at via the scheduler.',
        summary: 'Close maintenance request (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CloseMaintenanceRequest')),
        tags: ['Admin / Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Closed',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMaintenanceDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Only in_progress requests can be closed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function close(CloseMaintenanceRequest $request, int $id): JsonResponse
    {
        $req = $this->service->find($id);
        $this->authorize('closeAdmin', $req);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $updated = $this->service->close($req, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMaintenanceRequestDetailResource($updated))->resolve($request),
            __('admin.maintenance.closed'),
        );
    }
}
