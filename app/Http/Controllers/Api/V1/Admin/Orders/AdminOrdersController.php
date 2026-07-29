<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Orders\AddOrderNoteRequest;
use App\Http\Requests\Api\V1\Admin\Orders\AdminCancelOrderRequest;
use App\Http\Requests\Api\V1\Admin\Orders\ListAdminOrdersRequest;
use App\Http\Requests\Api\V1\Admin\Orders\ScheduleInstallationRequest;
use App\Http\Requests\Api\V1\Admin\Orders\UpdateOrderStatusRequest;
use App\Http\Resources\Api\V1\Admin\Orders\AdminOrderDetailResource;
use App\Http\Resources\Api\V1\Admin\Orders\AdminOrderResource;
use App\Http\Resources\Api\V1\Admin\Orders\AdminOrderStatusHistoryResource;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Services\Admin\AdminOrderService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * Admin-side orders controller.
 *
 * Thin per CLAUDE.md § 15 — validation lives in Form Requests, business
 * logic in {@see AdminOrderService}, authorization in {@see OrderPolicy}.
 *
 * Path parameters bind by integer id; {@see AdminOrderService::find()}
 * applies `withTrashed()` so admins can audit soft-deleted orders.
 *
 * See docs/architecture/admin-orders.md.
 */
final class AdminOrdersController extends Controller
{
    public function __construct(
        private readonly AdminOrderService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/orders',
        operationId: 'adminOrdersIndex',
        description: 'Paginated list across all orders. Filters: status (single or array), search (customer name/email + order public_id), period (shorthand for date_from/to), date range, payment_method, country (ISO-2 code), include_trashed (super_admin/operations only). Sort: created_at|placed_at|total_amount|status, default created_at desc.',
        summary: 'List orders (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Orders'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'scheduled', 'out_for_delivery', 'installed', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 160)),
            new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string', enum: ['today', 'yesterday', 'last_7_days', 'this_week', 'this_month', 'last_month']),
                description: 'Convenience shorthand that auto-populates date_from and date_to.'),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'payment_method', in: 'query', schema: new OA\Schema(type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod'])),
            new OA\Parameter(name: 'country', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2, example: 'AE')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'placed_at', 'total_amount', 'status'], default: 'created_at')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminOrderListResponse')),
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
    public function index(ListAdminOrdersRequest $request): JsonResponse
    {
        $this->authorize('viewAnyAdmin', Order::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->validated();

        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);
        if ($includeTrashed && ! $actor->can('orders.cancel-any')) {
            throw new AuthorizationException(__('admin.orders.trashed_forbidden'));
        }

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            [
                'counts' => $this->service->countByStatus(),
                'orders' => AdminOrderResource::collection($paginator),
            ],
            __('admin.orders.retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/orders/{id}',
        operationId: 'adminOrdersShow',
        description: 'Single-order detail including soft-deleted rows. Returns items, customer, addresses, status_history (with internal admin notes), installation, and the latest payment with gateway reference (admin context).',
        summary: 'Show order (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1057)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminOrderDetailResponse')),
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
        $order = $this->service->find($id);
        $this->authorize('viewAdmin', $order);

        return ApiResponse::success(
            (new AdminOrderDetailResource($order))->resolve($request),
            __('messages.ok'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/orders/{id}/status',
        operationId: 'adminOrdersUpdateStatus',
        description: 'Transition order status. Body: status, optional note. Actor = admin. Returns 409 ERR_INVALID_STATUS_TRANSITION when the state machine forbids the move; 409 ERR_USE_SCHEDULE_ENDPOINT when status=scheduled is supplied (use POST /schedule-installation).',
        summary: 'Update order status (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateOrderStatusRequest'),
        ),
        tags: ['Admin / Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminOrderDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Invalid status transition / use schedule endpoint',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function updateStatus(UpdateOrderStatusRequest $request, int $id): JsonResponse
    {
        $order = $this->service->find($id);
        $this->authorize('updateStatusAdmin', $order);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $target = OrderStatus::from((string) $payload['status']);
        $note = isset($payload['note']) && is_string($payload['note']) ? $payload['note'] : null;

        $updated = $this->service->transitionStatus($order, $target, $actor, $note, $request->ip());

        return ApiResponse::success(
            (new AdminOrderDetailResource($updated))->resolve($request),
            __('admin.orders.status_updated'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{id}/cancel',
        operationId: 'adminOrdersCancel',
        description: 'Admin cancel — accepts pending/confirmed/scheduled/out_for_delivery source states. Body: reason (5-1000 chars). Cancel from installed/completed/cancelled returns 409.',
        summary: 'Cancel order (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AdminCancelOrderRequest'),
        ),
        tags: ['Admin / Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminOrderDetailResponse')),
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
    public function cancel(AdminCancelOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->service->find($id);
        $this->authorize('cancelAdmin', $order);

        /** @var User $actor */
        $actor = $request->user();
        $reason = (string) $request->validated('reason');
        $cancelled = $this->service->cancel($order, $actor, $reason, $request->ip());

        return ApiResponse::success(
            (new AdminOrderDetailResource($cancelled))->resolve($request),
            __('admin.orders.cancelled'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{id}/schedule-installation',
        operationId: 'adminOrdersScheduleInstallation',
        description: 'Transition confirmed → scheduled AND upsert order_installations. Body: scheduled_for_at (future ISO-8601, optional — falls back to order delivery_date + delivery_time_slot), optional technician_user_id (must be an active operations admin), optional installation_notes.',
        summary: 'Schedule installation (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ScheduleInstallationRequest'),
        ),
        tags: ['Admin / Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Scheduled',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminOrderDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Source state not confirmed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function scheduleInstallation(ScheduleInstallationRequest $request, int $id): JsonResponse
    {
        $order = $this->service->find($id);
        $this->authorize('scheduleAdmin', $order);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();

        // Resolve scheduled_for_at: explicit payload wins, otherwise fall
        // back to the order's delivery_date + delivery_time_slot.
        $scheduledFor = isset($payload['scheduled_for_at']) && $payload['scheduled_for_at'] !== null
            ? Carbon::parse((string) $payload['scheduled_for_at'])
            : $this->resolveScheduledForFromOrder($order);

        $technicianUserId = isset($payload['technician_user_id'])
            ? (int) $payload['technician_user_id']
            : null;
        $installationNotes = isset($payload['installation_notes']) && is_string($payload['installation_notes'])
            ? $payload['installation_notes']
            : null;

        $updated = $this->service->scheduleInstallation(
            order: $order,
            scheduledFor: $scheduledFor,
            technicianUserId: $technicianUserId,
            installationNotes: $installationNotes,
            actor: $actor,
            ip: $request->ip(),
        );

        return ApiResponse::success(
            (new AdminOrderDetailResource($updated))->resolve($request),
            __('admin.orders.installation_scheduled'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/orders/{id}/notes',
        operationId: 'adminOrdersAddNote',
        description: 'Write an internal admin note as a notes-only row on order_status_history (from_status === to_status, is_internal=true). Filtered out of the customer-facing timeline. Rejected on terminal orders (409 ERR_INVALID_ORDER_STATE).',
        summary: 'Add admin note',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AddOrderNoteRequest'),
        ),
        tags: ['Admin / Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Note added',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminOrderNoteResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Order in terminal state',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function addNote(AddOrderNoteRequest $request, int $id): JsonResponse
    {
        $order = $this->service->find($id);
        $this->authorize('addNoteAdmin', $order);

        /** @var User $actor */
        $actor = $request->user();
        $note = (string) $request->validated('note');
        $entry = $this->service->addNote($order, $note, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminOrderStatusHistoryResource($entry))->resolve($request),
            __('admin.orders.note_added'),
            201,
        );
    }

    /**
     * Derive the installation schedule datetime from the order's
     * delivery_date + delivery_time_slot when the admin does not
     * supply an explicit scheduled_for_at.
     *
     * Time-slot mapping (GCC business hours):
     *   morning   → 08:00
     *   afternoon → 12:00
     *   evening   → 16:00
     *
     * Returns null when the order has neither delivery_date nor
     * delivery_time_slot — scheduling is permitted without a date.
     */
    private function resolveScheduledForFromOrder(Order $order): ?Carbon
    {
        $deliveryDate = $order->delivery_date;
        $timeSlot = $order->delivery_time_slot;

        if ($deliveryDate === null || $timeSlot === null) {
            return null;
        }

        $timeMap = [
            'morning' => '08:00:00',
            'afternoon' => '12:00:00',
            'evening' => '16:00:00',
        ];

        $time = $timeMap[$timeSlot] ?? '08:00:00';

        return Carbon::parse("{$deliveryDate->toDateString()} {$time}");
    }
}
