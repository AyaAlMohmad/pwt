<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Templates;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Templates\ListTemplatesRequest;
use App\Http\Requests\Api\V1\Admin\Templates\UpdateTemplateRequest;
use App\Http\Resources\Api\V1\Admin\Templates\NotificationTemplateDetailResource;
use App\Http\Resources\Api\V1\Admin\Templates\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Policies\NotificationTemplatePolicy;
use App\Providers\AppServiceProvider;
use App\Services\Admin\NotificationTemplateService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Phase 22 — Notification template editor (super_admin only).
 *
 * Thin controller — validation in
 * {@see ListTemplatesRequest} /
 * {@see UpdateTemplateRequest}, persistence in
 * {@see NotificationTemplateService}.
 *
 * Authorisation flows through {@see NotificationTemplatePolicy}
 * registered in {@see AppServiceProvider}.
 *
 * No CREATE / DELETE endpoints — see
 * docs/architecture/admin-audit-templates-dashboard.md § 9.4.
 */
final class AdminTemplatesController extends Controller
{
    public function __construct(
        private readonly NotificationTemplateService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/notification-templates',
        operationId: 'adminTemplatesIndex',
        description: 'Paginated notification templates (super_admin only). Filters: channel, key (substring), is_active. Returns BOTH locales for subject + body so the editor UI can hydrate side-by-side.',
        summary: 'List notification templates',
        security: [['sanctum' => []]],
        tags: ['Admin / Templates'],
        parameters: [
            new OA\Parameter(name: 'channel', in: 'query', schema: new OA\Schema(type: 'string', enum: ['in_app', 'sms', 'email', 'whatsapp', 'push'])),
            new OA\Parameter(name: 'key', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 64)),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['key', 'channel', 'updated_at'], default: 'key')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/NotificationTemplateCollectionResponse')),
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
    public function index(ListTemplatesRequest $request): JsonResponse
    {
        $this->authorize('viewAny', NotificationTemplate::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $paginator = $this->service->list($filters, $page, $perPage);

        return ApiResponse::success(
            NotificationTemplateResource::collection($paginator),
            __('admin.templates.retrieved'),
        );
    }

    #[OA\Put(
        path: '/api/v1/admin/notification-templates/{id}',
        operationId: 'adminTemplatesUpdate',
        description: 'Update bilingual subject + body + is_active (super_admin only). `key`, `channel`, and `placeholders` are immutable and silently dropped if supplied. The change is audit-logged with before/after diff under log name `admin.notification_templates`.',
        summary: 'Update a notification template',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateNotificationTemplateRequest')),
        tags: ['Admin / Templates'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/NotificationTemplateResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (super_admin only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Template not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdateTemplateRequest $request, int $id): JsonResponse
    {
        $template = $this->service->find($id);
        $this->authorize('update', $template);

        /** @var User $actor */
        $actor = $request->user();

        /** @var array{subject: array<string, string>, body: array<string, string>, is_active: bool} $payload */
        $payload = $request->validated();

        $updated = $this->service->update($template, $payload, $actor);

        return ApiResponse::success(
            (new NotificationTemplateDetailResource($updated))->resolve($request),
            __('admin.templates.updated'),
        );
    }
}
