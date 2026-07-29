<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Support\CancelSupportTicketRequest;
use App\Http\Requests\Api\V1\Support\CreateSupportTicketRequest;
use App\Http\Requests\Api\V1\Support\ListSupportTicketsRequest;
use App\Http\Resources\Api\V1\Support\SupportTicketDetailResource;
use App\Http\Resources\Api\V1\Support\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Customer-facing Support Tickets controller.
 *
 * Endpoints (per docs/architecture/support.md § 1):
 *   POST   /api/v1/support-tickets
 *   GET    /api/v1/support-tickets
 *   GET    /api/v1/support-tickets/{id}
 *   POST   /api/v1/support-tickets/{id}/cancel
 *
 * Admin endpoints (assign / transition / reply with internal notes,
 * etc.) live in Phase 19.
 */
final class SupportTicketsController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/support-tickets',
        operationId: 'supportTicketsStore',
        description: 'Submit a support ticket. Optional cross-links to an owned order or machine. Up to 5 support_attachment file ids may be attached. Rate-limited at 10/min/user.',
        summary: 'Submit a support ticket',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateSupportTicketRequest'),
        ),
        tags: ['Support'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/SupportTicketDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed (also ERR_FILE_NOT_OWNED)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateSupportTicketRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('create', SupportTicket::class);

        /** @var array{subject:string,category:string,message:string,related_order_id?:int|null,related_machine_id?:int|null,attachment_file_ids?:list<int>|null} $payload */
        $payload = $request->validated();
        $created = $this->service->submit($user, $payload);

        return ApiResponse::success(
            new SupportTicketDetailResource($created),
            __('support.ticket_created'),
            201,
        );
    }

    #[OA\Get(
        path: '/api/v1/support-tickets',
        operationId: 'supportTicketsIndex',
        description: "List the authenticated customer's support tickets. Filters: status, category. Sorted by last_message_at DESC.",
        summary: 'List support tickets',
        security: [['sanctum' => []]],
        tags: ['Support'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'status', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['open', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'])),
            new OA\Parameter(name: 'category', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['order_issue', 'payment_issue', 'maintenance_issue', 'product_question', 'account_issue', 'feedback', 'other'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/SupportTicketCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListSupportTicketsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('viewAny', SupportTicket::class);

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        /** @var array{status?:string|null, category?:string|null} $filters */
        $filters = $request->validated();
        $paginator = $this->service->listForUser($user, $filters, $page, $perPage);

        return ApiResponse::success(
            SupportTicketResource::collection($paginator),
            __('support.tickets_retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/support-tickets/{id}',
        operationId: 'supportTicketsShow',
        description: 'Show a single support ticket with full message thread (internal notes excluded), attachments, related order/machine summary, and status history.',
        summary: 'Show support ticket detail',
        security: [['sanctum' => []]],
        tags: ['Support'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 184)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/SupportTicketDetailResponse')),
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
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()->findOrFail($id);
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = request()->user();
        $loaded = $this->service->findForUser($ticket, $user);

        // Phase 19 — stamp the customer's read marker so the unread
        // message count accessor can compute a real value.
        $this->service->markCustomerViewed($loaded, $user);

        return ApiResponse::success(
            new SupportTicketDetailResource($loaded),
            __('support.ticket_retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/support-tickets/{id}/cancel',
        operationId: 'supportTicketsCancel',
        description: 'Cancel a support ticket you submitted. Allowed only when the current status is `open`; otherwise 409 ERR_INVALID_STATUS_TRANSITION.',
        summary: 'Cancel a support ticket',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CancelSupportTicketRequest'),
        ),
        tags: ['Support'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 184)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cancelled',
                content: new OA\JsonContent(ref: '#/components/schemas/SupportTicketDetailResponse')),
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
    public function cancel(CancelSupportTicketRequest $request, int $id): JsonResponse
    {
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()->findOrFail($id);
        $this->authorize('cancel', $ticket);

        /** @var User $user */
        $user = $request->user();
        $reason = (string) $request->validated('cancellation_reason');
        $cancelled = $this->service->cancel($ticket, $user, $reason);

        return ApiResponse::success(
            new SupportTicketDetailResource($cancelled),
            __('support.ticket_cancelled'),
        );
    }
}
