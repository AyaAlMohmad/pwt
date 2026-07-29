<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\AuditLogs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AuditLogs\ListAuditLogsRequest;
use App\Http\Resources\Api\V1\Admin\AuditLogs\AuditLogEntryResource;
use App\Policies\AuditLogPolicy;
use App\Providers\AppServiceProvider;
use App\Services\Admin\AuditLogService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Phase 22 — Admin audit-log read surface (super_admin only).
 *
 * Thin controller per CLAUDE.md § 15 — validation in
 * {@see ListAuditLogsRequest}, query / paginate in
 * {@see AuditLogService}, response shaping in {@see AuditLogEntryResource}.
 *
 * Authorisation is the named `audit-logs.view` gate registered in
 * {@see AppServiceProvider::registerPolicies()} which
 * delegates to {@see AuditLogPolicy}.
 */
final class AdminAuditLogsController extends Controller
{
    public function __construct(
        private readonly AuditLogService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/audit-logs',
        operationId: 'adminAuditLogsIndex',
        description: 'Paginated activity-log readings (super_admin only). Filters: actor_id, action (alias for log_name), log_name, event, subject_type (FQN or short alias — order, machine, etc.), subject_id, date_from, date_to, search (matches description). Sorted newest first. Default 50 rows, max 200.',
        summary: 'List audit-log entries',
        security: [['sanctum' => []]],
        tags: ['Admin / Audit Logs'],
        parameters: [
            new OA\Parameter(name: 'actor_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'action', in: 'query', description: 'Alias for log_name. Wins if both supplied.', schema: new OA\Schema(type: 'string', maxLength: 64)),
            new OA\Parameter(name: 'log_name', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 64)),
            new OA\Parameter(name: 'event', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 64)),
            new OA\Parameter(name: 'subject_type', in: 'query', description: 'FQN (App\\Models\\Order) or short alias (order, machine, maintenance_request, product, user, support_ticket, banner, promotion, testimonial, notification_template).', schema: new OA\Schema(type: 'string', maxLength: 255)),
            new OA\Parameter(name: 'subject_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 255)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AuditLogCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (super_admin only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListAuditLogsRequest $request): JsonResponse
    {
        $this->authorize('audit-logs.view');

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 50);

        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            AuditLogEntryResource::collection($paginator),
            __('admin.audit-logs.retrieved'),
        );
    }
}
