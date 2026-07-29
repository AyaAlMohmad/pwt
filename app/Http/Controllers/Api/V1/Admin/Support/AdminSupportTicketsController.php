<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Support\ListAdminSupportTicketsRequest;
use App\Http\Requests\Api\V1\Admin\Support\ReplyToSupportTicketRequest;
use App\Http\Requests\Api\V1\Admin\Support\UpdateAdminSupportTicketRequest;
use App\Http\Resources\Api\V1\Admin\Support\AdminSupportTicketDetailResource;
use App\Http\Resources\Api\V1\Admin\Support\AdminSupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\SupportTicketPolicy;
use App\Services\Admin\AdminSupportTicketService;
use App\Services\Support\SupportTicketService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Admin-side Support Tickets controller.
 *
 * Thin per CLAUDE.md § 15 — validation in Form Requests, business
 * logic in {@see AdminSupportTicketService}, authorisation through
 * {@see SupportTicketPolicy} admin methods. The state
 * machine itself remains in Phase 12's
 * {@see SupportTicketService}.
 *
 * See docs/architecture/admin-support.md.
 */
final class AdminSupportTicketsController extends Controller
{
    public function __construct(
        private readonly AdminSupportTicketService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/support-tickets',
        operationId: 'adminSupportIndex',
        description: 'Paginated list across all support tickets. Filters: search, status (single or array), category, priority, assigned_to_user_id (0 = unassigned), customer_id, date_from/to, include_trashed (super_admin). Default sort: last_message_at DESC.',
        summary: 'List support tickets (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Support'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 160)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'])),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: ['order_issue', 'payment_issue', 'maintenance_issue', 'product_question', 'account_issue', 'feedback', 'other'])),
            new OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'urgent'])),
            new OA\Parameter(name: 'assigned_to_user_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 0,
                description: '0 = unassigned; >0 = specific admin id.')),
            new OA\Parameter(name: 'customer_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'include_trashed', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['last_message_at', 'created_at', 'priority', 'status', 'opened_at'], default: 'last_message_at')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminSupportTicketCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAdminSupportTicketsRequest $request): JsonResponse
    {
        $this->authorize('viewAnyAdmin', SupportTicket::class);

        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->validated();

        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);
        if ($includeTrashed && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException(__('admin.support.trashed_forbidden'));
        }

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);
        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AdminSupportTicketResource::collection($paginator),
            __('admin.support.retrieved'),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/support-tickets/{id}',
        operationId: 'adminSupportShow',
        description: 'Single ticket detail — FULL thread INCLUDING internal notes, attachments, status history with full actor identities, related order/machine, customer profile.',
        summary: 'Show support ticket (admin)',
        security: [['sanctum' => []]],
        tags: ['Admin / Support'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminSupportTicketResponse')),
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
        $ticket = $this->service->find($id);
        $this->authorize('viewAdmin', $ticket);

        return ApiResponse::success(
            (new AdminSupportTicketDetailResource($ticket))->resolve($request),
            __('admin.support.show_retrieved'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/support-tickets/{id}',
        operationId: 'adminSupportUpdate',
        description: 'Update status, priority, and/or assignment. Drives the state machine via SupportTicketService::transitionTo() — invalid transitions surface as 409. Body status=open is rejected (reopen is Phase 2).',
        summary: 'Update support ticket (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAdminSupportTicketRequest')),
        tags: ['Admin / Support'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminSupportTicketResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Invalid transition / trashed ticket',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdateAdminSupportTicketRequest $request, int $id): JsonResponse
    {
        $ticket = $this->service->find($id);
        $this->authorize('updateAdmin', $ticket);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $updated = $this->service->update($ticket, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminSupportTicketDetailResource($updated))->resolve($request),
            __('admin.support.updated'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/support-tickets/{id}/reply',
        operationId: 'adminSupportReply',
        description: 'Post a reply on a support ticket. is_internal=true posts an admin-only note (permitted on terminal tickets). is_internal=false on a closed/cancelled ticket returns 409 ERR_TICKET_TERMINAL. Stamps first_response_at on the first customer-visible reply.',
        summary: 'Reply to support ticket (admin)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ReplyToSupportTicketRequest')),
        tags: ['Admin / Support'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Reply posted',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminSupportTicketResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Terminal ticket / trashed ticket',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function reply(ReplyToSupportTicketRequest $request, int $id): JsonResponse
    {
        $ticket = $this->service->find($id);
        $this->authorize('replyAdmin', $ticket);

        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();
        $updated = $this->service->reply($ticket, $payload, $actor, $request->ip());

        return ApiResponse::success(
            (new AdminSupportTicketDetailResource($updated))->resolve($request),
            __('admin.support.replied'),
            201,
        );
    }
}
