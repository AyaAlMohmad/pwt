<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Machines;

use App\Enums\MaintenanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Machines\CloseMachineMaintenanceRequest;
use App\Http\Requests\Api\V1\Admin\Machines\CreateMachineRequest;
use App\Http\Requests\Api\V1\Admin\Machines\DueMaintenanceRequest;
use App\Http\Requests\Api\V1\Admin\Machines\ListAdminMachinesRequest;
use App\Http\Requests\Api\V1\Admin\Machines\UpdateMachineMaintenanceStatusRequest;
use App\Http\Requests\Api\V1\Admin\Machines\UpdateMachineRequest;
use App\Http\Resources\Api\V1\Admin\Machines\AdminMachineDetailResource;
use App\Http\Resources\Api\V1\Admin\Machines\AdminMachineResource;
use App\Http\Resources\Api\V1\Admin\Maintenance\AdminMaintenanceRequestDetailResource;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Policies\MachinePolicy;
use App\Policies\MaintenanceRequestPolicy;
use App\Services\Admin\AdminMachineService;
use App\Services\Admin\AdminMaintenanceService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side machines controller.
 *
 * Thin per CLAUDE.md § 15 — validation lives in Form Requests, business
 * logic in {@see AdminMachineService}, authorization in
 * {@see MachinePolicy}.
 *
 * See docs/architecture/admin-machines-maintenance.md.
 */
final class AdminMachinesController extends Controller
{
    public function __construct(
        private readonly AdminMachineService $service,
        private readonly AdminMaintenanceService $maintenanceService,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/machines',
        operationId: 'adminMachinesIndex',
        description: 'Paginated list across all machines. Filters: search, country_code, product_id, customer_id, needs_maintenance (with within_days lookahead), is_active, status (rented/purchased), source, term, include_trashed (super_admin only). Sort: created_at|installed_at|next_maintenance_due_at|serial_number.',
        summary: 'List machines (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Machines'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 160)),
            new OA\Parameter(name: 'country_code', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2, example: 'AE')),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'customer_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'needs_maintenance', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'within_days', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 365, default: 30)),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['rented', 'purchased'])),
            new OA\Parameter(name: 'source', in: 'query', schema: new OA\Schema(type: 'string', enum: ['imported', 'created'])),
            new OA\Parameter(name: 'term', in: 'query', schema: new OA\Schema(type: 'string', enum: ['buy', 'rent', 'foc'])),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string', enum: ['today', 'yesterday', 'last_7_days', 'this_week', 'this_month', 'last_month'])),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'installed_at', 'next_maintenance_due_at', 'serial_number'], default: 'created_at')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMachineCollectionResponse')),
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
    public function index(ListAdminMachinesRequest $request): JsonResponse
    {
        $this->authorize('viewAnyAdmin', Machine::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->validated();

        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);
        if ($includeTrashed && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException(__('admin.machines.trashed_forbidden'));
        }

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminMachineResource::collection($paginator),
            __('admin.machines.retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/machines/due-maintenance',
        operationId: 'adminMachinesDueMaintenance',
        description: 'Machines whose next_maintenance_due_at is within ?within_days (default 30, max 365). Forced sort: next_maintenance_due_at ASC.',
        summary: 'Machines due for maintenance (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Machines'],
        parameters: [
            new OA\Parameter(name: 'within_days', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 365, default: 30)),
            new OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 365, default: 30, description: 'Alias of within_days.')),
            new OA\Parameter(name: 'country_code', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2)),
            new OA\Parameter(name: 'customer_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMachineCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function dueMaintenance(DueMaintenanceRequest $request): JsonResponse
    {
        $this->authorize('dueMaintenanceAdmin', Machine::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->service->dueMaintenance($filters, $page, $perPage);

        return ApiResponse::success(
            AdminMachineResource::collection($paginator),
            __('admin.machines.due_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/machines/{id}',
        operationId: 'adminMachinesShow',
        description: 'Single-machine detail including soft-deleted rows. Returns notes, latest 50 maintenance logs, latest 20 maintenance requests.',
        summary: 'Show machine (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Machines'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMachineDetailResponse')),
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
        $machine = $this->service->find($id);
        $this->authorize('viewAdmin', $machine);

        return ApiResponse::success(
            (new AdminMachineDetailResource($machine))->resolve($request),
            __('admin.machines.show_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/machines',
        operationId: 'adminMachinesStore',
        description: 'Create a machine for an existing customer. Source forced to `created`; order_id forced to null. If filter_change_interval_months is set without an explicit next_maintenance_due_at, the scheduler computes the next due date.',
        summary: 'Create machine (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateMachineRequest')),
        tags: ['Admin / Machines'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMachineDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateMachineRequest $request): JsonResponse
    {
        $this->authorize('createAdmin', Machine::class);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $machine = $this->service->create($payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMachineDetailResource($machine))->resolve($request),
            __('admin.machines.created'),
            201,
        );
    }

    #[OA\Put(
        path: '/api/v1/admin/machines/{id}',
        operationId: 'adminMachinesUpdate',
        description: 'Update a machine. `user_id` (ownership) and `product_id` can be reassigned; changing `user_id` requires an `address_id` owned by the new customer in the same request. Recomputes next_maintenance_due_at when filter_change_interval_months changes AND no explicit override is supplied.',
        summary: 'Update machine (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateMachineRequest')),
        tags: ['Admin / Machines'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMachineDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Resource trashed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdateMachineRequest $request, int $id): JsonResponse
    {
        $machine = $this->service->find($id);
        $this->authorize('updateAdmin', $machine);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $updated = $this->service->update($machine, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMachineDetailResource($updated))->resolve($request),
            __('admin.machines.updated'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/machines/{id}/maintenance-status',
        operationId: 'adminMachineUpdateMaintenanceStatus',
        description: 'Update the status of the latest open maintenance request for a machine. Returns the updated maintenance request detail. If no open request exists, returns 404.',
        summary: 'Update machine maintenance status (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAdminMaintenanceStatusRequest')),
        tags: ['Admin / Machines'],
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
            new OA\Response(response: 409, description: 'Invalid state transition',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function updateMaintenanceStatus(UpdateMachineMaintenanceStatusRequest $request, int $id): JsonResponse
    {
        $machine = $this->service->find($id);
        $this->authorize('updateAdmin', $machine);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();

        $openRequest = $this->maintenanceService->findOrCreateOpenRequestForMachine($machine, $actor);

        $this->authorize('updateStatusAdmin', $openRequest);

        $updated = $this->maintenanceService->transition($openRequest, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMaintenanceRequestDetailResource($updated))->resolve($request),
            __('admin.maintenance.status_updated'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/machines/{id}/maintenance-close',
        operationId: 'adminMachineCloseMaintenanceStatus',
        description: 'Close (complete) the latest in_progress maintenance request for a machine. Writes resolution_notes, inserts a machine_maintenance_logs row, recomputes next_maintenance_due_at.',
        summary: 'Close machine maintenance request (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CloseMaintenanceRequest')),
        tags: ['Admin / Machines'],
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
    public function closeMaintenanceStatus(CloseMachineMaintenanceRequest $request, int $id): JsonResponse
    {
        $machine = $this->service->find($id);
        $this->authorize('updateAdmin', $machine);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();

        $openRequest = $this->maintenanceService->findOrCreateOpenRequestForMachine($machine, $actor);

        // Close requires in_progress status. Auto-transition if needed.
        if ($openRequest->status !== MaintenanceStatus::InProgress) {
            $openRequest = $this->maintenanceService->transition(
                $openRequest,
                ['status' => MaintenanceStatus::InProgress->value, 'reason' => 'Auto-transition for close'],
                $actor,
                $request->ip(),
            );
        }

        $this->authorize('closeAdmin', $openRequest);

        $updated = $this->maintenanceService->close($openRequest, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminMaintenanceRequestDetailResource($updated))->resolve($request),
            __('admin.maintenance.closed'),
        );
    }
}
