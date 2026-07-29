<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Orders\CancelOrderRequest;
use App\Http\Requests\Api\V1\Orders\CreateOrderRequest;
use App\Http\Requests\Api\V1\Orders\ListOrdersRequest;
use App\Http\Resources\Api\V1\Orders\OrderDetailResource;
use App\Http\Resources\Api\V1\Orders\OrderResource;
use App\Http\Resources\Api\V1\Orders\OrderTrackingResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatusTransitionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Customer-facing Orders controller.
 *
 * Methods are kept ≤ 30 LOC per CLAUDE.md § 15 — validation lives in
 * Form Requests, money + state in {@see OrderService}, transitions in
 * {@see OrderStatusTransitionService}.
 */
final class OrdersController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
    ) {}

    #[OA\Post(
        path: '/api/v1/orders',
        operationId: 'ordersStore',
        description: 'Place a new order from a cart. Server recomputes every monetary field; client-supplied totals or unit_price are rejected with 422. Idempotent via the optional Idempotency-Key header (24h replay window). Rate-limited at 5 requests/minute/user.',
        summary: 'Place an order',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateOrderRequest'),
        ),
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 80, pattern: '^[A-Za-z0-9_\-]+$')),
            new OA\Parameter(name: 'Accept-Language', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', enum: ['en', 'ar'], default: 'en')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Idempotent replay — original order returned',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderResponse')),
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed (also ERR_PROMO_INVALID, ERR_PRODUCT_UNAVAILABLE)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('create', Order::class);

        $idempotencyKey = $this->resolveIdempotencyKey($request);
        [$order, $replayed] = $this->orders->create($user, $request->validated(), $idempotencyKey);

        return ApiResponse::success(
            (new OrderResource($order))->resolve($request),
            $replayed ? __('orders.order_replayed') : __('orders.order_placed'),
            $replayed ? 200 : 201,
        );
    }

    #[OA\Get(
        path: '/api/v1/orders',
        operationId: 'ordersIndex',
        description: "List the caller's orders. Filters: status, date_from, date_to. Sorted by created_at DESC. Paginated (default 15, max 100).",
        summary: 'List orders',
        security: [['sanctum' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'status', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'scheduled', 'out_for_delivery', 'installed', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'date_from', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListOrdersRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('viewAny', Order::class);

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->orders->listForUser($user, $request->validated(), $page, $perPage);

        $stats = $this->buildDashboardStats($user);
        $orders = OrderResource::collection($paginator)->resolve();

        return ApiResponse::success(
            [
                'stats' => $stats,
                'orders' => $orders,
            ],
            __('orders.orders_retrieved'),
            200,
            ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]],
        );
    }

    #[OA\Get(
        path: '/api/v1/orders/dashboard',
        operationId: 'ordersDashboard',
        description: "Dashboard view of the caller's orders with aggregate stats. Filters: status, date_from, date_to. Sorted by created_at DESC. Paginated (default 15, max 100).",
        summary: 'Orders dashboard with stats',
        security: [['sanctum' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'status', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'scheduled', 'out_for_delivery', 'installed', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'date_from', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderDashboardResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function dashboard(ListOrdersRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('viewAny', Order::class);

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->orders->listForUser($user, $request->validated(), $page, $perPage);

        $stats = $this->buildDashboardStats($user);
        $orders = OrderResource::collection($paginator)->resolve();

        return ApiResponse::success(
            [
                'stats' => $stats,
                'orders' => $orders,
            ],
            __('orders.dashboard_retrieved'),
            200,
            ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]],
        );
    }

    /**
     * Build aggregate stats for the orders dashboard.
     *
     * @return array<string, mixed>
     */
    private function buildDashboardStats(User $user): array
    {
        $userId = (int) $user->id;
        $terminalStatuses = ['completed', 'cancelled'];

        $openPosQuery = Order::query()
            ->forUser($userId)
            ->whereNotIn('status', $terminalStatuses);

        $openPos = (clone $openPosQuery)->count();
        $awaitingApproval = (clone $openPosQuery)->where('status', 'pending')->count();

        $unitsOnOrder = (int) DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $userId)
            ->whereNotIn('orders.status', $terminalStatuses)
            ->sum('order_items.quantity');

        $sites = DB::table('orders')
            ->where('user_id', $userId)
            ->whereNotIn('status', $terminalStatuses)
            ->distinct('delivery_address_id')
            ->count('delivery_address_id');

        $quotations = Order::query()
            ->forUser($userId)
            ->where('status', 'pending')
            ->count();

        $valueResult = DB::table('orders')
            ->selectRaw('SUM(CAST(total_amount AS DECIMAL(15,2))) as total, currency')
            ->where('user_id', $userId)
            ->whereNotIn('status', $terminalStatuses)
            ->groupBy('currency')
            ->first();

        $valueOnOrder = $valueResult !== null ? (string) $valueResult->total : '0.00';
        $currency = $valueResult !== null ? (string) $valueResult->currency : 'AED';

        return [
            'open_pos' => [
                'total' => $openPos,
                'awaiting_approval' => $awaitingApproval,
            ],
            'units_on_order' => [
                'total' => $unitsOnOrder,
                'sites' => $sites,
            ],
            'quotations' => [
                'total' => $quotations,
                'pending_review' => $quotations,
            ],
            'value_on_order' => [
                'amount' => $valueOnOrder,
                'currency' => $currency,
            ],
        ];
    }

    #[OA\Get(
        path: '/api/v1/orders/{order}',
        operationId: 'ordersShow',
        description: 'Show a single order. Returns the full detail shape: items, status_history, delivery_address, billing_address, promotion. Ownership failure returns 403 (not 404) per CLAUDE.md § 7.3.',
        summary: 'Show order detail',
        security: [['sanctum' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1057)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);
        $order = $this->orders->eagerLoad($order);

        return ApiResponse::success(
            (new OrderDetailResource($order))->resolve($request),
            __('orders.order_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/orders/{order}/cancel',
        operationId: 'ordersCancel',
        description: 'Cancel a pending or confirmed order. Allowed status: pending, confirmed. Otherwise 409 ERR_INVALID_STATUS_TRANSITION. Paid orders surface a follow-up refund message — actual refund is admin-manual in MVP. Rate-limited at 10/min/user.',
        summary: 'Cancel an order',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CancelOrderRequest'),
        ),
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1057)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderResponse')),
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
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);
        /** @var User $user */
        $user = $request->user();

        $reason = (string) $request->validated('cancellation_reason');
        $cancelled = $this->orders->cancel($order, $user, $reason);

        $messageKey = $cancelled->confirmed_at !== null
            ? 'orders.cancel_with_refund_note'
            : 'orders.cancel_succeeded';

        return ApiResponse::success(
            (new OrderResource($cancelled))->resolve($request),
            __($messageKey),
        );
    }

    #[OA\Get(
        path: '/api/v1/orders/{order}/tracking',
        operationId: 'ordersTracking',
        description: 'Tracking projection: status, progress %, next step, estimated delivery window, full timeline. Ownership-scoped; 403 on mismatch.',
        summary: 'Order tracking',
        security: [['sanctum' => []]],
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1057)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderTrackingResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function tracking(Request $request, Order $order): JsonResponse
    {
        $this->authorize('track', $order);
        $payload = $this->orders->tracking($order);

        return ApiResponse::success(
            (new OrderTrackingResource($payload))->resolve($request),
            __('orders.tracking_retrieved'),
        );
    }

    /**
     * Pull the Idempotency-Key header and validate its shape. Returns
     * null when absent. A malformed header surfaces a 422 ERR_VALIDATION
     * — we throw the same form the validator would.
     */
    private function resolveIdempotencyKey(Request $request): ?string
    {
        $raw = $request->header('Idempotency-Key');
        if ($raw === null || $raw === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $raw) !== 1) {
            abort(response()->json([
                'success' => false,
                'message' => __('orders.idempotency_key_invalid'),
                'errors' => ['Idempotency-Key' => [__('orders.idempotency_key_invalid')]],
                'code' => 'ERR_VALIDATION',
            ], 422));
        }

        return $raw;
    }
}
