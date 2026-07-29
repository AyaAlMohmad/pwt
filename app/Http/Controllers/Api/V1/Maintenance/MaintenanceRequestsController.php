<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Maintenance\CancelMaintenanceRequest;
use App\Http\Requests\Api\V1\Maintenance\ListMaintenanceRequestsRequest;
use App\Http\Requests\Api\V1\Maintenance\RescheduleMaintenanceRequest;
use App\Http\Requests\Api\V1\Maintenance\StoreMaintenanceRequest;
use App\Http\Resources\Api\V1\Maintenance\MaintenanceRequestDetailResource;
use App\Http\Resources\Api\V1\Maintenance\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\Maintenance\MaintenanceRequestService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Customer-facing Maintenance Requests controller.
 *
 * Endpoints (per docs/architecture/machines-maintenance.md § 1):
 *   POST   /api/v1/maintenance-requests
 *   GET    /api/v1/maintenance-requests
 *   GET    /api/v1/maintenance-requests/{id}
 *   POST   /api/v1/maintenance-requests/{id}/cancel
 *
 * Admin endpoints (`PATCH .../assign`, `.../progress`, `.../complete`,
 * `.../cancel`) live in Phase 18.
 */
final class MaintenanceRequestsController extends Controller
{
    public function __construct(
        private readonly MaintenanceRequestService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/maintenance-requests',
        operationId: 'maintenanceRequestsStore',
        description: 'Submit a maintenance request against an owned, active machine. The customer-supplied priority is silently capped at medium (admin elevates via Phase 18). Up to 5 maintenance_media file ids may be attached. Rate-limited at 10/min/user.',
        summary: 'Submit a maintenance request',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreMaintenanceRequestRequest'),
        ),
        tags: ['Maintenance'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/MaintenanceRequestResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed (also ERR_MACHINE_NOT_OWNED, ERR_FILE_NOT_OWNED)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(StoreMaintenanceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('create', MaintenanceRequest::class);

        $payload = $request->validated();

        // Batch creation: multiple machines in one request.
        if (isset($payload['machine_ids']) && is_array($payload['machine_ids'])) {
            $requests = $this->service->submitBatch($user, $payload);

            return ApiResponse::success(
                MaintenanceRequestDetailResource::collection(collect($requests)),
                __('maintenance.requests_submitted'),
                201,
            );
        }

        $created = $this->service->submit($user, $payload);

        return ApiResponse::success(
            new MaintenanceRequestDetailResource($created),
            __('maintenance.request_submitted'),
            201,
        );
    }

    #[OA\Get(
        path: '/api/v1/maintenance-requests',
        operationId: 'maintenanceRequestsIndex',
        description: "List the authenticated customer's maintenance requests. Filters: status, date_from, date_to. Sorted by created_at DESC by default.",
        summary: 'List maintenance requests',
        security: [['sanctum' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'status', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['new', 'assigned', 'in_progress', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'date_from', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MaintenanceRequestCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListMaintenanceRequestsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('viewAny', MaintenanceRequest::class);

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->service->listForUser($user, $request->validated(), $page, $perPage);

        return ApiResponse::success(
            MaintenanceRequestResource::collection($paginator),
            __('maintenance.requests_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/maintenance-requests/{id}',
        operationId: 'maintenanceRequestsShow',
        description: 'Show a single maintenance request. Includes machine summary, media (signed URLs), full status history, and (conditionally) resolution_notes / cancellation_reason.',
        summary: 'Show maintenance request detail',
        security: [['sanctum' => []]],
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 88)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MaintenanceRequestResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        /** @var MaintenanceRequest $request */
        $request = MaintenanceRequest::query()->findOrFail($id);
        $this->authorize('view', $request);

        $loaded = $this->service->eagerLoad($request);

        return ApiResponse::success(
            new MaintenanceRequestDetailResource($loaded),
            __('maintenance.request_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/maintenance-requests/{id}/reschedule',
        operationId: 'maintenanceRequestsReschedule',
        description: 'Reschedule a maintenance visit. Allowed when the request is not in a terminal state (completed or cancelled).',
        summary: 'Reschedule a maintenance visit',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/RescheduleMaintenanceRequest'),
        ),
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 88)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rescheduled',
                content: new OA\JsonContent(ref: '#/components/schemas/MaintenanceRequestResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Invalid status transition',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function reschedule(RescheduleMaintenanceRequest $request, int $id): JsonResponse
    {
        /** @var MaintenanceRequest $maintenanceRequest */
        $maintenanceRequest = MaintenanceRequest::query()->findOrFail($id);
        $this->authorize('reschedule', $maintenanceRequest);

        /** @var User $user */
        $user = $request->user();
        $updated = $this->service->reschedule($maintenanceRequest, $user, $request->validated());

        return ApiResponse::success(
            new MaintenanceRequestDetailResource($updated),
            __('maintenance.request_rescheduled'),
        );
    }

    #[OA\Post(
        path: '/api/v1/maintenance-requests/{id}/cancel',
        operationId: 'maintenanceRequestsCancel',
        description: 'Cancel a maintenance request you submitted. Allowed only when the current status is `new`; otherwise 409 ERR_INVALID_STATUS_TRANSITION.',
        summary: 'Cancel a maintenance request',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CancelMaintenanceRequest'),
        ),
        tags: ['Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 88)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled',
                content: new OA\JsonContent(ref: '#/components/schemas/MaintenanceRequestResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Invalid status transition',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function cancel(CancelMaintenanceRequest $request, int $id): JsonResponse
    {
        /** @var MaintenanceRequest $maintenanceRequest */
        $maintenanceRequest = MaintenanceRequest::query()->findOrFail($id);
        $this->authorize('cancel', $maintenanceRequest);

        /** @var User $user */
        $user = $request->user();
        $reason = (string) $request->validated('cancellation_reason');

        $cancelled = $this->service->cancel($maintenanceRequest, $user, $reason);

        return ApiResponse::success(
            new MaintenanceRequestDetailResource($cancelled),
            __('maintenance.request_cancelled'),
        );
    }
}
