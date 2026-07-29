<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notifications\ListNotificationsRequest;
use App\Http\Resources\Api\V1\Notifications\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use App\Policies\NotificationPolicy;
use App\Services\Notifications\NotificationReadService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Customer-facing Notifications inbox controller.
 *
 * Three thin actions backed by {@see NotificationReadService}. Ownership
 * enforced via {@see NotificationPolicy} → 403 on foreign
 * id (CLAUDE.md § 7.3, orchestrator decision 1).
 */
final class NotificationsController extends Controller
{
    public function __construct(
        private readonly NotificationReadService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/notifications',
        operationId: 'notificationsIndex',
        description: "List the authenticated customer's notifications. Filters: unread_only, type, date_from, date_to. Sorted by created_at DESC. Paginated (default 15, max 100). The response `meta.unread_count` field carries the global unread total across all pages.",
        summary: 'List notifications',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'unread_only', in: 'query', required: false,
                schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'type', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', maxLength: 64, example: 'order.confirmed')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/NotificationCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('viewAny', Notification::class);

        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $paginator = $this->service->listForUser($user, $request->validated(), $page, $perPage);
        $unreadCount = $this->service->unreadCount($user);

        return ApiResponse::success(
            NotificationResource::collection($paginator),
            __('notifications.list_retrieved'),
            meta: ['unread_count' => $unreadCount],
        );
    }

    #[OA\Patch(
        path: '/api/v1/notifications/{id}/read',
        operationId: 'notificationsMarkRead',
        description: 'Mark a single notification as read. Idempotent — re-marking an already-read row preserves the existing read_at and returns 200. Ownership-scoped: 403 on foreign id (per CLAUDE.md § 7.3).',
        summary: 'Mark a notification as read',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 4815)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marked as read',
                content: new OA\JsonContent(ref: '#/components/schemas/NotificationResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function markRead(Request $request, int $id): JsonResponse
    {
        /** @var Notification $notification */
        $notification = Notification::query()->findOrFail($id);
        $this->authorize('update', $notification);

        $updated = $this->service->markRead($notification);

        return ApiResponse::success(
            (new NotificationResource($updated))->resolve($request),
            __('notifications.marked_read'),
        );
    }

    #[OA\Post(
        path: '/api/v1/notifications/read-all',
        operationId: 'notificationsMarkAllRead',
        description: 'Mark every unread notification belonging to the authenticated user as read. Returns the number of rows updated in meta.updated_count.',
        summary: 'Mark all notifications as read',
        security: [['sanctum' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'All marked',
                content: new OA\JsonContent(ref: '#/components/schemas/ReadAllNotificationsResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->service->markAllRead($user);

        return ApiResponse::success(
            null,
            __('notifications.all_marked_read'),
            meta: ['updated_count' => $updated],
        );
    }
}
