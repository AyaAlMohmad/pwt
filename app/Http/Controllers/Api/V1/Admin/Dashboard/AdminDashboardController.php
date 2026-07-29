<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Dashboard;

use App\Enums\DashboardPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Dashboard\DashboardSnapshotResource;
use App\Services\Admin\DashboardService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Phase 22 — Admin KPI dashboard snapshot.
 *
 * Open to ANY admin role — the `admin` middleware (Phase 14) is the sole
 * gate. No permission key required per the architect's brief.
 *
 * The underlying aggregation is cached for 60 s (config `dashboard.cache_ttl`)
 * — see docs/architecture/admin-audit-templates-dashboard.md § 6.5.
 */
final class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/dashboard',
        operationId: 'adminDashboardShow',
        description: 'KPI snapshot for the admin landing page. Optional `period` filter changes the current vs previous comparison window for trend metrics. Absolute state metrics (e.g. pending maintenance, total active machines) are unaffected. Open to any admin role. Cached server-side for 60 s; meta.cached indicates whether this response was served from cache.',
        summary: 'Admin dashboard snapshot',
        security: [['sanctum' => []]],
        tags: ['Admin / Dashboard'],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', schema: new OA\Schema(type: 'string', enum: ['today', 'yesterday', 'last_7_days', 'this_week', 'this_month', 'last_month'], default: 'today'),
                description: 'Time window for trend comparisons. Defaults to `today`.'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/DashboardResponse')),
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
    public function show(Request $request): JsonResponse
    {
        $period = DashboardPeriod::tryFrom((string) $request->query('period', DashboardPeriod::Today->value))
            ?? DashboardPeriod::default();

        // The cache must be probed BEFORE `snapshot()` is called — once
        // remember() runs the key is always present, so a freshly-computed
        // response would otherwise report `cached=true`.
        $cached = $this->service->isCached($period);
        $snapshot = $this->service->snapshot($period);

        return ApiResponse::success(
            (new DashboardSnapshotResource($snapshot))->resolve($request),
            __('admin.dashboard.retrieved'),
            200,
            [
                'generated_at' => $snapshot->generatedAt->toIso8601String(),
                'cache_ttl_seconds' => (int) config('dashboard.cache_ttl', 60),
                'cached' => $cached,
                'period' => $period->value,
            ],
        );
    }
}
