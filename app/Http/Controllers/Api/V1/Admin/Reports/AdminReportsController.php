<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reports\CustomersReportRequest;
use App\Http\Requests\Api\V1\Admin\Reports\ExportReportRequest;
use App\Http\Requests\Api\V1\Admin\Reports\MaintenanceReportRequest;
use App\Http\Requests\Api\V1\Admin\Reports\OrdersReportRequest;
use App\Http\Requests\Api\V1\Admin\Reports\SalesReportRequest;
use App\Http\Resources\Api\V1\Admin\Reports\CustomersReportRowResource;
use App\Http\Resources\Api\V1\Admin\Reports\MaintenanceReportRowResource;
use App\Http\Resources\Api\V1\Admin\Reports\OrdersReportRowResource;
use App\Http\Resources\Api\V1\Admin\Reports\SalesReportRowResource;
use App\Policies\ReportPolicy;
use App\Providers\AppServiceProvider;
use App\Services\Reports\Exporters\ExporterFactory;
use App\Services\Reports\Exporters\ExporterInterface;
use App\Services\Reports\ReportBuilder;
use App\Support\Http\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 21 — Admin reports & exports controller.
 *
 * Thin per CLAUDE.md § 15 — validation lives in Form Requests, aggregation
 * in {@see ReportBuilder}, response shaping in the four
 * `*ReportRowResource` classes, and streaming in
 * {@see ExporterInterface}. Authorisation
 * is via the named `reports.access` / `reports.export-access` gates
 * registered in {@see AppServiceProvider::registerPolicies()} which
 * delegate to {@see ReportPolicy}. The named gates are intentionally
 * distinct from the spatie permission keys (`reports.view` /
 * `reports.export`) to avoid the Gate::before short-circuit (see
 * PROD-ISSUE-21-1).
 *
 * The 4 read endpoints return the standard JSON envelope with
 * `{ filters, summary, buckets, rows }`. Pagination meta lifted from the
 * paginator lands under `meta.pagination`. Row shapes (nested customer /
 * machine / assigned_to structures) live in the per-type API Resources so
 * exports and reads share the same allowlist boundary.
 *
 * The export endpoint returns a {@see StreamedResponse} directly — the
 * standard JSON envelope is bypassed on success. Error paths (401/403/422)
 * still use the JSON envelope via the global exception handler.
 *
 * See docs/architecture/admin-reports.md.
 */
final class AdminReportsController extends Controller
{
    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly ExporterFactory $exporters,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/reports/sales',
        operationId: 'adminReportsSales',
        description: 'Revenue + order value by date range. Filters: date_from/to, product_id, country (ISO-2), term, payment_method, bucket. Cancelled orders excluded from revenue (rows still list them).',
        summary: 'Sales report',
        security: [['sanctum' => []]],
        tags: ['Admin / Reports'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'country', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2, example: 'AE')),
            new OA\Parameter(name: 'term', in: 'query', schema: new OA\Schema(type: 'string', enum: ['buy', 'rent', 'foc'])),
            new OA\Parameter(name: 'payment_method', in: 'query', schema: new OA\Schema(type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod'])),
            new OA\Parameter(name: 'bucket', in: 'query', schema: new OA\Schema(type: 'string', enum: ['auto', 'daily', 'monthly'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'total_amount', 'product_code'], default: 'created_at')),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/SalesReportResponse')),
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
    public function sales(SalesReportRequest $request): JsonResponse
    {
        $this->authorize('reports.access', ReportType::Sales);

        $report = $this->builder->sales($request->validated(), app()->getLocale());

        return $this->respond($request, $report, SalesReportRowResource::class, __('admin.reports.sales_generated'));
    }

    #[OA\Get(
        path: '/api/v1/admin/reports/orders',
        operationId: 'adminReportsOrders',
        description: 'Counts per status + paginated rows. Filters: date range, status (single or array), country, payment_method, term.',
        summary: 'Orders report',
        security: [['sanctum' => []]],
        tags: ['Admin / Reports'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'country', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2)),
            new OA\Parameter(name: 'payment_method', in: 'query', schema: new OA\Schema(type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod'])),
            new OA\Parameter(name: 'term', in: 'query', schema: new OA\Schema(type: 'string', enum: ['buy', 'rent', 'foc'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'total_amount', 'status'])),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/OrdersReportResponse')),
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
    public function orders(OrdersReportRequest $request): JsonResponse
    {
        $this->authorize('reports.access', ReportType::Orders);

        $report = $this->builder->orders($request->validated(), app()->getLocale());

        return $this->respond($request, $report, OrdersReportRowResource::class, __('admin.reports.orders_generated'));
    }

    #[OA\Get(
        path: '/api/v1/admin/reports/maintenance',
        operationId: 'adminReportsMaintenance',
        description: 'Service requests by status / priority / assigned technician / date. Counts per status + per priority + paginated rows. Includes average resolution hours for completed rows.',
        summary: 'Maintenance report',
        security: [['sanctum' => []]],
        tags: ['Admin / Reports'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'assigned_to_user_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'issue_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'urgent'])),
            new OA\Parameter(name: 'country', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'status', 'priority'])),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MaintenanceReportResponse')),
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
    public function maintenance(MaintenanceReportRequest $request): JsonResponse
    {
        $this->authorize('reports.access', ReportType::Maintenance);

        $report = $this->builder->maintenance($request->validated(), app()->getLocale());

        return $this->respond($request, $report, MaintenanceReportRowResource::class, __('admin.reports.maintenance_generated'));
    }

    #[OA\Get(
        path: '/api/v1/admin/reports/customers',
        operationId: 'adminReportsCustomers',
        description: 'Registered customers + activity. Filters: registration date range, country (resolved via default address), has_orders, is_active. Buckets are grouped by (month, country).',
        summary: 'Customers report',
        security: [['sanctum' => []]],
        tags: ['Admin / Reports'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'country', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2)),
            new OA\Parameter(name: 'has_orders', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created_at', 'order_count', 'total_spend', 'last_order_at', 'name'])),
            new OA\Parameter(name: 'order', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CustomersReportResponse')),
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
    public function customers(CustomersReportRequest $request): JsonResponse
    {
        $this->authorize('reports.access', ReportType::Customers);

        $report = $this->builder->customers($request->validated(), app()->getLocale());

        return $this->respond($request, $report, CustomersReportRowResource::class, __('admin.reports.customers_generated'));
    }

    #[OA\Get(
        path: '/api/v1/admin/reports/{type}/export',
        operationId: 'adminReportsExport',
        description: 'Streamed CSV/XLSX of the chosen report. Capped at 10 000 rows; the response carries X-PWT-Export-Truncated and X-PWT-Export-Row-Count headers. Audit-logged with log name `report.export`.',
        summary: 'Export report (CSV / XLSX)',
        security: [['sanctum' => []]],
        tags: ['Admin / Reports'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true,
                schema: new OA\Schema(type: 'string', enum: ['sales', 'orders', 'maintenance', 'customers'])),
            new OA\Parameter(name: 'format', in: 'query', required: true,
                schema: new OA\Schema(type: 'string', enum: ['csv', 'xlsx'])),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'country', in: 'query', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK — binary stream',
                content: [
                    new OA\MediaType(mediaType: 'text/csv'),
                    new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
                ]),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown report type',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function export(ExportReportRequest $request, string $type): StreamedResponse
    {
        $reportType = ReportType::from($type);
        $this->authorize('reports.export-access', $reportType);

        /** @var array<string, mixed> $filters */
        $filters = $request->validated();
        $format = (string) $filters['format'];

        $data = $this->builder->exportData($reportType, $filters, app()->getLocale());
        $this->logExport($request, $reportType, $format, $filters, $data);

        return $this->exporters->make($format)->export($data);
    }

    /**
     * Standard JSON envelope for the four read endpoints, lifting the
     * paginator out of `rows` so pagination meta lands under
     * `meta.pagination` per CLAUDE.md § 5.4.
     *
     * @param  array{filters: array<string, mixed>, summary: array<string, mixed>, buckets: list<array<string, mixed>>, rows: LengthAwarePaginator<int, object>}  $report
     * @param  class-string<JsonResource>  $rowResource
     */
    private function respond(Request $request, array $report, string $rowResource, string $message): JsonResponse
    {
        /** @var LengthAwarePaginator<int, object> $paginator */
        $paginator = $report['rows'];

        $rows = array_map(
            static fn (object $row): array => (new $rowResource($row))->toArray($request),
            $paginator->items(),
        );

        return ApiResponse::success(
            [
                'filters' => $report['filters'],
                'summary' => $report['summary'],
                'buckets' => $report['buckets'],
                'rows' => $rows,
            ],
            $message,
            200,
            ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ]],
        );
    }

    /**
     * Emit one `report.export` activity row per successful export call.
     *
     * The `filters` array is the validated payload (includes resolved
     * defaults like the 30-day date window) so the audit row records
     * exactly what was queried — not the raw user query string.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{header: list<string>, rows: \Generator, total: int, truncated: bool, type: string, date_from: string, date_to: string}  $data
     */
    private function logExport(Request $request, ReportType $type, string $format, array $filters, array $data): void
    {
        $rowCount = min($data['total'], ReportBuilder::EXPORT_ROW_CAP);

        activity('report.export')
            ->event('report.exported')
            ->causedBy($request->user())
            ->withProperties([
                'type' => $type->value,
                'format' => $format,
                'filters' => $filters,
                'row_count' => $rowCount,
                'total_matching' => $data['total'],
                'truncated' => $data['truncated'],
                'requested_ip' => (string) $request->ip(),
            ])
            ->log('Admin exported report');
    }
}
