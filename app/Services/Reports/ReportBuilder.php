<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Enums\ReportType;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 21 — Admin reports orchestrator.
 *
 * Builds the four read reports + the cap-aware export row iterators. All
 * aggregation runs in MySQL via the Query Builder bindings; no PHP-level
 * summing or raw-string interpolation of user input.
 *
 * Public surface:
 *   - sales(filters, locale) → ['filters', 'summary', 'buckets', 'rows']
 *   - orders(filters, locale)
 *   - maintenance(filters, locale)
 *   - customers(filters, locale)
 *   - exportQuery(type, filters, locale) → ['header', 'iterator', 'total', 'truncated']
 *
 * See docs/architecture/admin-reports.md §§ 3, 4, 6.7.
 */
final class ReportBuilder
{
    public const EXPORT_ROW_CAP = 10000;

    private const DEFAULT_RANGE_DAYS = 30;

    private const DAILY_BUCKET_THRESHOLD_DAYS = 60;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filters: array<string, mixed>, summary: array<string, mixed>, buckets: list<array<string, mixed>>, rows: LengthAwarePaginator<int, object>}
     */
    public function sales(array $filters, string $locale = 'en'): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $bucket = $this->resolveBucket($from, $to, $this->stringOrNull($filters['bucket'] ?? null));

        $filters['date_from'] = $from->toDateString();
        $filters['date_to'] = $to->toDateString();
        $filters['bucket'] = $bucket;

        $summary = $this->salesSummary($from, $to, $filters);
        $buckets = $this->salesBuckets($from, $to, $bucket, $filters);
        $rows = $this->salesRows($from, $to, $filters);

        return [
            'filters' => $this->echoFilters($filters, ReportType::Sales),
            'summary' => $summary,
            'buckets' => $buckets,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filters: array<string, mixed>, summary: array<string, mixed>, buckets: list<array<string, mixed>>, rows: LengthAwarePaginator<int, object>}
     */
    public function orders(array $filters, string $locale = 'en'): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $bucket = $this->resolveBucket($from, $to, null);

        $filters['date_from'] = $from->toDateString();
        $filters['date_to'] = $to->toDateString();
        $filters['bucket'] = $bucket;

        $summary = $this->ordersSummary($from, $to, $filters);
        $buckets = $this->ordersBuckets($from, $to, $bucket, $filters);
        $rows = $this->ordersRows($from, $to, $filters);

        return [
            'filters' => $this->echoFilters($filters, ReportType::Orders),
            'summary' => $summary,
            'buckets' => $buckets,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filters: array<string, mixed>, summary: array<string, mixed>, buckets: list<array<string, mixed>>, rows: LengthAwarePaginator<int, object>}
     */
    public function maintenance(array $filters, string $locale = 'en'): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $bucket = $this->resolveBucket($from, $to, null);

        $filters['date_from'] = $from->toDateString();
        $filters['date_to'] = $to->toDateString();
        $filters['bucket'] = $bucket;

        $summary = $this->maintenanceSummary($from, $to, $filters);
        $buckets = $this->maintenanceBuckets($from, $to, $bucket, $filters);
        $rows = $this->maintenanceRows($from, $to, $filters);

        return [
            'filters' => $this->echoFilters($filters, ReportType::Maintenance),
            'summary' => $summary,
            'buckets' => $buckets,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filters: array<string, mixed>, summary: array<string, mixed>, buckets: list<array<string, mixed>>, rows: LengthAwarePaginator<int, object>}
     */
    public function customers(array $filters, string $locale = 'en'): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $bucket = $this->resolveBucket($from, $to, null);

        $filters['date_from'] = $from->toDateString();
        $filters['date_to'] = $to->toDateString();
        $filters['bucket'] = $bucket;

        $summary = $this->customersSummary($from, $to, $filters);
        $buckets = $this->customersBuckets($from, $to, $bucket, $filters);
        $rows = $this->customersRows($from, $to, $filters);

        return [
            'filters' => $this->echoFilters($filters, ReportType::Customers),
            'summary' => $summary,
            'buckets' => $buckets,
            'rows' => $rows,
        ];
    }

    /**
     * Build an export iterator capped at {@see self::EXPORT_ROW_CAP} rows.
     *
     * Returns the column header list, a generator yielding row arrays, the
     * resolved row count, and a truncated flag. The generator chunks by
     * 500 rows to keep memory bounded.
     *
     * @param  array<string, mixed>  $filters
     * @return array{header: list<string>, rows: \Generator, total: int, truncated: bool, type: string, date_from: string, date_to: string}
     */
    public function exportData(ReportType $type, array $filters, string $locale = 'en'): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $filters['date_from'] = $from->toDateString();
        $filters['date_to'] = $to->toDateString();

        $query = match ($type) {
            ReportType::Sales => $this->salesRowQuery($from, $to, $filters),
            ReportType::Orders => $this->ordersRowQuery($from, $to, $filters),
            ReportType::Maintenance => $this->maintenanceRowQuery($from, $to, $filters),
            ReportType::Customers => $this->customersRowQuery($from, $to, $filters),
        };

        // PROD-ISSUE-21-2 fix: the sales row query carries a GROUP BY on
        // orders.id (plus the selected columns) so that an order with
        // multiple order_items doesn't multiply once a product_id filter
        // joins order_items. Laravel's QueryBuilder::count() strips the
        // groupBy aggregate and returns the underlying joined row count,
        // which over-reports both the X-PWT-Export-Row-Count header and
        // the truncated flag. Wrapping in a subquery preserves the
        // GROUP BY so the count reflects distinct grouped rows.
        $total = (int) DB::query()
            ->fromSub((clone $query), 'sub')
            ->count();
        $effective = min($total, self::EXPORT_ROW_CAP);

        $header = match ($type) {
            ReportType::Sales => $this->salesHeader(),
            ReportType::Orders => $this->ordersHeader(),
            ReportType::Maintenance => $this->maintenanceHeader(),
            ReportType::Customers => $this->customersHeader(),
        };

        $iterator = $this->yieldRows($query, $type, $effective);

        return [
            'header' => $header,
            'rows' => $iterator,
            'total' => $total,
            'truncated' => $total > self::EXPORT_ROW_CAP,
            'type' => $type->value,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ];
    }

    // ---------- Date / bucket resolution ----------

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(array $filters): array
    {
        $tz = 'Asia/Dubai';
        $from = $this->stringOrNull($filters['date_from'] ?? null);
        $to = $this->stringOrNull($filters['date_to'] ?? null);

        $today = Carbon::today($tz);

        if ($from === null && $to === null) {
            return [$today->copy()->subDays(self::DEFAULT_RANGE_DAYS)->startOfDay(), $today->copy()->endOfDay()];
        }
        if ($from !== null && $to === null) {
            $start = Carbon::createFromFormat('Y-m-d', $from, $tz);
            $start = $start === null ? $today->copy() : $start->startOfDay();

            return [$start, $today->copy()->endOfDay()];
        }
        if ($from === null) {
            // Implied by the two `if` branches above: $to is non-null here.
            $end = Carbon::createFromFormat('Y-m-d', (string) $to, $tz);
            $end = $end === null ? $today->copy() : $end->endOfDay();

            return [$end->copy()->subDays(self::DEFAULT_RANGE_DAYS)->startOfDay(), $end];
        }

        $start = Carbon::createFromFormat('Y-m-d', $from, $tz);
        $end = Carbon::createFromFormat('Y-m-d', (string) $to, $tz);
        $start = $start === null ? $today->copy()->subDays(self::DEFAULT_RANGE_DAYS) : $start;
        $end = $end === null ? $today->copy() : $end;

        return [$start->startOfDay(), $end->endOfDay()];
    }

    private function resolveBucket(Carbon $from, Carbon $to, ?string $explicit): string
    {
        if ($explicit === 'daily' || $explicit === 'monthly') {
            return $explicit;
        }

        $days = $from->diffInDays($to, true);

        return $days <= self::DAILY_BUCKET_THRESHOLD_DAYS ? 'daily' : 'monthly';
    }

    // ---------- Sales ----------

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function salesSummary(Carbon $from, Carbon $to, array $filters): array
    {
        $query = $this->salesBaseQuery($from, $to, $filters)
            ->whereIn('orders.status', $this->salesRevenueStatuses($filters));

        $row = (object) (array) $query->selectRaw(
            'COUNT(*) as orders_count, '
            .'CAST(COALESCE(SUM(orders.subtotal_amount), 0) AS DECIMAL(14,2)) as subtotal_gross, '
            .'CAST(COALESCE(SUM(orders.discount_amount), 0) AS DECIMAL(14,2)) as discount_total, '
            .'CAST(COALESCE(SUM(orders.tax_amount), 0) AS DECIMAL(14,2)) as tax_total, '
            .'CAST(COALESCE(SUM(orders.shipping_amount), 0) AS DECIMAL(14,2)) as shipping_total, '
            .'CAST(COALESCE(SUM(orders.total_amount), 0) AS DECIMAL(14,2)) as net_revenue'
        )->first();

        $ordersCount = (int) ($row->orders_count ?? 0);
        $net = (float) ($row->net_revenue ?? 0);
        $aov = $ordersCount > 0 ? round($net / $ordersCount, 2) : 0.0;

        $summary = [
            'orders_count' => $ordersCount,
            'currency' => $this->resolveCurrency($filters),
            'subtotal_gross' => number_format((float) ($row->subtotal_gross ?? 0), 2, '.', ''),
            'discount_total' => number_format((float) ($row->discount_total ?? 0), 2, '.', ''),
            'tax_total' => number_format((float) ($row->tax_total ?? 0), 2, '.', ''),
            'shipping_total' => number_format((float) ($row->shipping_total ?? 0), 2, '.', ''),
            'net_revenue' => number_format($net, 2, '.', ''),
            'average_order_value' => number_format($aov, 2, '.', ''),
        ];

        if (in_array('cancelled', $this->statusFilter($filters), true)) {
            $summary['note'] = __('admin.reports.sales_cancelled_only_note');
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function salesBuckets(Carbon $from, Carbon $to, string $bucket, array $filters): array
    {
        $expr = $bucket === 'daily' ? "DATE_FORMAT(orders.created_at, '%Y-%m-%d')" : "DATE_FORMAT(orders.created_at, '%Y-%m')";

        $rows = $this->salesBaseQuery($from, $to, $filters)
            ->whereIn('orders.status', $this->salesRevenueStatuses($filters))
            ->selectRaw(
                $expr.' as bucket, '
                .'COUNT(*) as orders_count, '
                .'CAST(COALESCE(SUM(orders.subtotal_amount), 0) AS DECIMAL(14,2)) as subtotal_gross, '
                .'CAST(COALESCE(SUM(orders.discount_amount), 0) AS DECIMAL(14,2)) as discount_total, '
                .'CAST(COALESCE(SUM(orders.tax_amount), 0) AS DECIMAL(14,2)) as tax_total, '
                .'CAST(COALESCE(SUM(orders.total_amount), 0) AS DECIMAL(14,2)) as net_revenue'
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(static fn ($r): array => [
            'bucket' => (string) $r->bucket,
            'orders_count' => (int) $r->orders_count,
            'subtotal_gross' => number_format((float) $r->subtotal_gross, 2, '.', ''),
            'discount_total' => number_format((float) $r->discount_total, 2, '.', ''),
            'tax_total' => number_format((float) $r->tax_total, 2, '.', ''),
            'net_revenue' => number_format((float) $r->net_revenue, 2, '.', ''),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    private function salesRows(Carbon $from, Carbon $to, array $filters): LengthAwarePaginator
    {
        $query = $this->salesRowQuery($from, $to, $filters);

        $sort = $this->mapSalesSort($this->stringOrNull($filters['sort'] ?? null));
        $order = $this->stringOrNull($filters['order'] ?? null) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function salesRowQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        $base = $this->salesBaseQuery($from, $to, $filters);
        $statuses = $this->statusFilter($filters);
        if ($statuses !== []) {
            $base->whereIn('orders.status', $statuses);
        }

        return $base
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('countries', 'countries.id', '=', 'addresses.country_id')
            ->select([
                'orders.id as order_id',
                'orders.public_id as order_public_id',
                'orders.created_at as placed_at',
                'orders.status as status',
                'orders.term as term',
                'orders.payment_method as payment_method',
                'users.id as customer_id',
                'users.name as customer_name',
                'users.email as customer_email',
                'users.phone as customer_phone',
                'users.phone_country_code as customer_phone_cc',
                'countries.code as country_code',
                'orders.subtotal_amount',
                'orders.discount_amount',
                'orders.tax_amount',
                'orders.shipping_amount',
                'orders.total_amount',
                'orders.currency',
            ])
            ->groupBy([
                'orders.id', 'orders.public_id', 'orders.created_at', 'orders.status',
                'orders.term', 'orders.payment_method',
                'users.id', 'users.name', 'users.email', 'users.phone', 'users.phone_country_code',
                'countries.code',
                'orders.subtotal_amount', 'orders.discount_amount', 'orders.tax_amount',
                'orders.shipping_amount', 'orders.total_amount', 'orders.currency',
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function salesBaseQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        $query = DB::table('orders')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$from, $to])
            ->leftJoin('addresses', 'addresses.id', '=', 'orders.delivery_address_id');

        $country = $this->stringOrNull($filters['country'] ?? null);
        if ($country !== null) {
            $query->whereIn('addresses.country_id', function (QueryBuilder $sub) use ($country): void {
                $sub->from('countries')->select('id')->where('code', $country);
            });
        }

        $term = $this->stringOrNull($filters['term'] ?? null);
        if ($term !== null) {
            $query->where('orders.term', $term);
        }

        $paymentMethod = $this->stringOrNull($filters['payment_method'] ?? null);
        if ($paymentMethod !== null) {
            $query->where('orders.payment_method', $paymentMethod);
        }

        $productId = isset($filters['product_id']) && is_numeric($filters['product_id'])
            ? (int) $filters['product_id']
            : null;

        if ($productId !== null) {
            $query->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $productId);
        }

        return $query;
    }

    /**
     * Sales revenue statuses — exclude `cancelled` by default unless the
     * caller explicitly filters for it (in which case the row list still
     * shows them and the note is surfaced in the summary).
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function salesRevenueStatuses(array $filters): array
    {
        $statuses = $this->statusFilter($filters);
        if ($statuses === []) {
            $all = OrderStatus::values();

            return array_values(array_filter($all, static fn (string $s): bool => $s !== 'cancelled'));
        }

        return $statuses;
    }

    /**
     * @return list<string>
     */
    private function salesHeader(): array
    {
        return [
            'order_id', 'order_public_id', 'placed_at', 'status', 'term', 'payment_method',
            'customer_id', 'customer_name', 'customer_email', 'customer_phone',
            'country_code', 'subtotal_amount', 'discount_amount', 'tax_amount',
            'shipping_amount', 'total_amount', 'currency',
        ];
    }

    // ---------- Orders ----------

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function ordersSummary(Carbon $from, Carbon $to, array $filters): array
    {
        $query = $this->ordersBaseQuery($from, $to, $filters);

        $aggregate = (object) (array) (clone $query)
            ->selectRaw('COUNT(*) as orders_count, CAST(COALESCE(SUM(orders.total_amount), 0) AS DECIMAL(14,2)) as total_gross')
            ->first();

        $perStatus = (clone $query)
            ->selectRaw('orders.status as status, COUNT(*) as cnt')
            ->groupBy('orders.status')
            ->pluck('cnt', 'status');

        $byStatus = [];
        foreach (OrderStatus::values() as $status) {
            $byStatus[$status] = (int) ($perStatus[$status] ?? 0);
        }

        return [
            'orders_count' => (int) ($aggregate->orders_count ?? 0),
            'total_gross' => number_format((float) ($aggregate->total_gross ?? 0), 2, '.', ''),
            'currency' => $this->resolveCurrency($filters),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function ordersBuckets(Carbon $from, Carbon $to, string $bucket, array $filters): array
    {
        $expr = $bucket === 'daily' ? "DATE_FORMAT(orders.created_at, '%Y-%m-%d')" : "DATE_FORMAT(orders.created_at, '%Y-%m')";

        $rows = $this->ordersBaseQuery($from, $to, $filters)
            ->selectRaw($expr.' as bucket, COUNT(*) as orders_count, CAST(COALESCE(SUM(orders.total_amount), 0) AS DECIMAL(14,2)) as total_gross')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(static fn ($r): array => [
            'bucket' => (string) $r->bucket,
            'orders_count' => (int) $r->orders_count,
            'total_gross' => number_format((float) $r->total_gross, 2, '.', ''),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    private function ordersRows(Carbon $from, Carbon $to, array $filters): LengthAwarePaginator
    {
        $query = $this->ordersRowQuery($from, $to, $filters);

        $sort = match ($this->stringOrNull($filters['sort'] ?? null)) {
            'total_amount' => 'orders.total_amount',
            'status' => 'orders.status',
            default => 'orders.created_at',
        };
        $order = $this->stringOrNull($filters['order'] ?? null) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function ordersRowQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        return $this->ordersBaseQuery($from, $to, $filters)
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('countries', 'countries.id', '=', 'addresses.country_id')
            ->select([
                'orders.id as order_id',
                'orders.public_id as order_public_id',
                'orders.created_at as placed_at',
                'orders.status as status',
                'orders.term as term',
                'orders.payment_method as payment_method',
                'users.name as customer_name',
                'users.email as customer_email',
                'users.phone as customer_phone',
                'users.phone_country_code as customer_phone_cc',
                'countries.code as country_code',
                'orders.total_amount',
                'orders.currency',
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function ordersBaseQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        $query = DB::table('orders')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$from, $to])
            ->leftJoin('addresses', 'addresses.id', '=', 'orders.delivery_address_id');

        $statuses = $this->statusFilter($filters);
        if ($statuses !== []) {
            $query->whereIn('orders.status', $statuses);
        }

        $country = $this->stringOrNull($filters['country'] ?? null);
        if ($country !== null) {
            $query->whereIn('addresses.country_id', function (QueryBuilder $sub) use ($country): void {
                $sub->from('countries')->select('id')->where('code', $country);
            });
        }

        $paymentMethod = $this->stringOrNull($filters['payment_method'] ?? null);
        if ($paymentMethod !== null) {
            $query->where('orders.payment_method', $paymentMethod);
        }

        $term = $this->stringOrNull($filters['term'] ?? null);
        if ($term !== null) {
            $query->where('orders.term', $term);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function ordersHeader(): array
    {
        return [
            'order_id', 'order_public_id', 'placed_at', 'status', 'term', 'payment_method',
            'customer_name', 'customer_email', 'customer_phone',
            'country_code', 'total_amount', 'currency',
        ];
    }

    // ---------- Maintenance ----------

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function maintenanceSummary(Carbon $from, Carbon $to, array $filters): array
    {
        $base = $this->maintenanceBaseQuery($from, $to, $filters);

        $countRow = (object) (array) (clone $base)->selectRaw('COUNT(*) as requests_count')->first();

        $perStatus = (clone $base)
            ->selectRaw('maintenance_requests.status as status, COUNT(*) as cnt')
            ->groupBy('maintenance_requests.status')
            ->pluck('cnt', 'status');

        $perPriority = (clone $base)
            ->selectRaw('maintenance_requests.priority as priority, COUNT(*) as cnt')
            ->groupBy('maintenance_requests.priority')
            ->pluck('cnt', 'priority');

        $avgRow = (object) (array) (clone $base)
            ->selectRaw("AVG(CASE WHEN maintenance_requests.status = 'completed' AND maintenance_requests.closed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, maintenance_requests.created_at, maintenance_requests.closed_at) END) as avg_hours")
            ->first();

        return [
            'requests_count' => (int) ($countRow->requests_count ?? 0),
            'by_status' => $this->fillStatusMap($perStatus, ['new', 'assigned', 'in_progress', 'completed', 'cancelled']),
            'by_priority' => $this->fillStatusMap($perPriority, ['low', 'medium', 'high', 'urgent']),
            'average_resolution_hours' => $avgRow->avg_hours !== null ? round((float) $avgRow->avg_hours, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function maintenanceBuckets(Carbon $from, Carbon $to, string $bucket, array $filters): array
    {
        $expr = $bucket === 'daily' ? "DATE_FORMAT(maintenance_requests.created_at, '%Y-%m-%d')" : "DATE_FORMAT(maintenance_requests.created_at, '%Y-%m')";

        $rows = $this->maintenanceBaseQuery($from, $to, $filters)
            ->selectRaw($expr." as bucket, COUNT(*) as requests_count, SUM(CASE WHEN maintenance_requests.status = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(static fn ($r): array => [
            'bucket' => (string) $r->bucket,
            'requests_count' => (int) $r->requests_count,
            'completed_count' => (int) $r->completed_count,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    private function maintenanceRows(Carbon $from, Carbon $to, array $filters): LengthAwarePaginator
    {
        $query = $this->maintenanceRowQuery($from, $to, $filters);

        $sort = match ($this->stringOrNull($filters['sort'] ?? null)) {
            'status' => 'maintenance_requests.status',
            'priority' => 'maintenance_requests.priority',
            default => 'maintenance_requests.created_at',
        };
        $order = $this->stringOrNull($filters['order'] ?? null) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function maintenanceRowQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        return $this->maintenanceBaseQuery($from, $to, $filters)
            ->leftJoin('users as customer', 'customer.id', '=', 'maintenance_requests.user_id')
            ->leftJoin('users as technician', 'technician.id', '=', 'maintenance_requests.assigned_to_user_id')
            ->leftJoin('machines', 'machines.id', '=', 'maintenance_requests.machine_id')
            ->leftJoin('products', 'products.id', '=', 'machines.product_id')
            ->select([
                'maintenance_requests.id as id',
                'maintenance_requests.public_id as public_id',
                'maintenance_requests.created_at as created_at',
                'maintenance_requests.status as status',
                'maintenance_requests.priority as priority',
                'maintenance_requests.issue_type as issue_type',
                'maintenance_requests.closed_at as closed_at',
                'customer.id as customer_id',
                'customer.name as customer_name',
                'customer.email as customer_email',
                'customer.phone as customer_phone',
                'customer.phone_country_code as customer_phone_cc',
                'machines.id as machine_id',
                'machines.serial_number as serial_number',
                'machines.country_code as machine_country',
                'machines.deleted_at as machine_deleted_at',
                'products.code as product_code',
                'technician.id as technician_id',
                'technician.name as technician_name',
                DB::raw('CASE WHEN maintenance_requests.status = \'completed\' AND maintenance_requests.closed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, maintenance_requests.created_at, maintenance_requests.closed_at) ELSE NULL END as resolution_hours'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function maintenanceBaseQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        $query = DB::table('maintenance_requests')
            ->whereNull('maintenance_requests.deleted_at')
            ->whereBetween('maintenance_requests.created_at', [$from, $to]);

        $statuses = $this->statusFilter($filters);
        if ($statuses !== []) {
            $query->whereIn('maintenance_requests.status', $statuses);
        }

        $assignedTo = isset($filters['assigned_to_user_id']) && is_numeric($filters['assigned_to_user_id'])
            ? (int) $filters['assigned_to_user_id']
            : null;
        if ($assignedTo !== null) {
            $query->where('maintenance_requests.assigned_to_user_id', $assignedTo);
        }

        $issueType = $this->stringOrNull($filters['issue_type'] ?? null);
        if ($issueType !== null) {
            $query->where('maintenance_requests.issue_type', $issueType);
        }

        $priority = $this->stringOrNull($filters['priority'] ?? null);
        if ($priority !== null) {
            $query->where('maintenance_requests.priority', $priority);
        }

        $country = $this->stringOrNull($filters['country'] ?? null);
        if ($country !== null) {
            $query->whereIn('maintenance_requests.machine_id', function (QueryBuilder $sub) use ($country): void {
                $sub->from('machines')->select('id')->where('country_code', $country);
            });
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function maintenanceHeader(): array
    {
        return [
            'id', 'public_id', 'created_at', 'status', 'priority', 'issue_type',
            'customer_id', 'customer_name', 'customer_email', 'customer_phone',
            'machine_id', 'serial_number', 'product_code', 'machine_country',
            'technician_id', 'technician_name',
            'closed_at', 'resolution_hours',
        ];
    }

    // ---------- Customers ----------

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function customersSummary(Carbon $from, Carbon $to, array $filters): array
    {
        $base = $this->customersBaseQuery($from, $to, $filters);

        $count = (int) (clone $base)->count();

        $active = (int) (clone $base)->where('users.is_active', true)->count();

        $withOrders = (int) (clone $base)
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->from('orders')
                    ->whereColumn('orders.user_id', 'users.id')
                    ->whereNull('orders.deleted_at')
                    ->where('orders.status', '!=', 'cancelled');
            })->count();

        $withMachines = (int) (clone $base)
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->from('machines')
                    ->whereColumn('machines.user_id', 'users.id')
                    ->whereNull('machines.deleted_at');
            })->count();

        return [
            'customers_count' => $count,
            'active_count' => $active,
            'with_orders_count' => $withOrders,
            'with_machines_count' => $withMachines,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function customersBuckets(Carbon $from, Carbon $to, string $bucket, array $filters): array
    {
        $expr = $bucket === 'daily' ? "DATE_FORMAT(users.created_at, '%Y-%m-%d')" : "DATE_FORMAT(users.created_at, '%Y-%m')";

        $rows = $this->customersBaseQuery($from, $to, $filters)
            ->leftJoin('countries as bucket_country', 'bucket_country.id', '=', 'default_address.country_id')
            ->selectRaw($expr.' as bucket_value, bucket_country.code as country_code, COUNT(*) as new_customers_count')
            ->groupBy('bucket_value', 'bucket_country.code')
            ->orderBy('bucket_value')
            ->orderBy('bucket_country.code')
            ->get();

        return $rows->map(static fn ($r): array => [
            'bucket' => (string) $r->bucket_value,
            'country_code' => $r->country_code !== null ? (string) $r->country_code : null,
            'new_customers_count' => (int) $r->new_customers_count,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    private function customersRows(Carbon $from, Carbon $to, array $filters): LengthAwarePaginator
    {
        $query = $this->customersRowQuery($from, $to, $filters);

        $sort = match ($this->stringOrNull($filters['sort'] ?? null)) {
            'order_count' => 'order_count',
            'total_spend' => 'total_spend',
            'last_order_at' => 'last_order_at',
            'name' => 'users.name',
            default => 'users.created_at',
        };
        $order = $this->stringOrNull($filters['order'] ?? null) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function customersRowQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        return $this->customersBaseQuery($from, $to, $filters)
            ->leftJoin('countries as resolved_country', 'resolved_country.id', '=', 'default_address.country_id')
            ->select([
                'users.id as id',
                'users.name as name',
                'users.email as email',
                'users.phone as phone',
                'users.phone_country_code as phone_country_code',
                'users.is_active as is_active',
                'users.created_at as created_at',
                'resolved_country.code as country_code',
                DB::raw('(SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id AND o.deleted_at IS NULL AND o.status != \'cancelled\') as order_count'),
                DB::raw('(SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.user_id = users.id AND o.deleted_at IS NULL AND o.status != \'cancelled\') as total_spend'),
                DB::raw('(SELECT MAX(o.created_at) FROM orders o WHERE o.user_id = users.id AND o.deleted_at IS NULL AND o.status != \'cancelled\') as last_order_at'),
                DB::raw('(SELECT COUNT(*) FROM machines m WHERE m.user_id = users.id AND m.deleted_at IS NULL) as machines_count'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function customersBaseQuery(Carbon $from, Carbon $to, array $filters): QueryBuilder
    {
        // "default_address" is a virtual per-customer row whose
        // country_id prefers is_default=true; if none, the most recent
        // address. Customers with no addresses still appear via the
        // LEFT JOIN (country_id is null).
        $defaultAddressSub = DB::table('addresses')
            ->selectRaw(
                'user_id, '
                .'COALESCE('
                .'MAX(CASE WHEN is_default = 1 THEN country_id END), '
                .'(SELECT country_id FROM addresses a2 WHERE a2.user_id = addresses.user_id AND a2.deleted_at IS NULL ORDER BY id DESC LIMIT 1)'
                .') as country_id'
            )
            ->whereNull('deleted_at')
            ->groupBy('user_id');

        // Restrict to the `customer` role via the spatie pivot.
        $customerRoleSub = DB::table('model_has_roles')
            ->select('model_has_roles.model_id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'customer')
            ->where('model_has_roles.model_type', User::class);

        $query = DB::table('users')
            ->whereNull('users.deleted_at')
            ->whereBetween('users.created_at', [$from, $to])
            ->whereIn('users.id', $customerRoleSub)
            ->leftJoinSub($defaultAddressSub, 'default_address', static function (JoinClause $j): void {
                $j->on('default_address.user_id', '=', 'users.id');
            });

        $country = $this->stringOrNull($filters['country'] ?? null);
        if ($country !== null) {
            $query->whereIn('default_address.country_id', function (QueryBuilder $sub) use ($country): void {
                $sub->from('countries')->select('id')->where('code', $country);
            });
        }

        $isActive = $this->boolOrNull($filters['is_active'] ?? null);
        if ($isActive !== null) {
            $query->where('users.is_active', $isActive);
        }

        $hasOrders = $this->boolOrNull($filters['has_orders'] ?? null);
        if ($hasOrders === true) {
            $query->whereExists(function (QueryBuilder $sub): void {
                $sub->from('orders')
                    ->whereColumn('orders.user_id', 'users.id')
                    ->whereNull('orders.deleted_at')
                    ->where('orders.status', '!=', 'cancelled');
            });
        } elseif ($hasOrders === false) {
            $query->whereNotExists(function (QueryBuilder $sub): void {
                $sub->from('orders')
                    ->whereColumn('orders.user_id', 'users.id')
                    ->whereNull('orders.deleted_at')
                    ->where('orders.status', '!=', 'cancelled');
            });
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function customersHeader(): array
    {
        return [
            'id', 'name', 'email', 'phone', 'country_code', 'is_active',
            'created_at', 'order_count', 'total_spend', 'last_order_at', 'machines_count',
        ];
    }

    // ---------- Export iterator ----------

    /**
     * Yield rows from the export query in chunks of 500. Each yielded row
     * is an associative array keyed by the report header. Capped at
     * {@see self::EXPORT_ROW_CAP} rows.
     */
    private function yieldRows(QueryBuilder $query, ReportType $type, int $cap): \Generator
    {
        if ($cap <= 0) {
            return;
        }

        $sort = $this->defaultSortColumnForExport($type);
        $offset = 0;
        $chunkSize = 500;

        while ($offset < $cap) {
            $take = min($chunkSize, $cap - $offset);
            /** @var array<int, object> $rows */
            $rows = (clone $query)
                ->orderBy($sort, 'desc')
                ->orderBy($this->primaryKeyForExport($type), 'desc')
                ->offset($offset)
                ->limit($take)
                ->get()
                ->all();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield $this->normalizeExportRow($type, (array) $row);
            }

            $offset += $take;

            if (count($rows) < $take) {
                return;
            }
        }
    }

    private function defaultSortColumnForExport(ReportType $type): string
    {
        return match ($type) {
            ReportType::Sales, ReportType::Orders => 'placed_at',
            ReportType::Maintenance => 'created_at',
            ReportType::Customers => 'users.created_at',
        };
    }

    private function primaryKeyForExport(ReportType $type): string
    {
        return match ($type) {
            ReportType::Sales, ReportType::Orders => 'order_id',
            ReportType::Maintenance => 'id',
            ReportType::Customers => 'users.id',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function normalizeExportRow(ReportType $type, array $row): array
    {
        return match ($type) {
            ReportType::Sales => [
                'order_id' => (string) ($row['order_id'] ?? ''),
                'order_public_id' => (string) ($row['order_public_id'] ?? ''),
                'placed_at' => $this->stringifyDateTime($row['placed_at'] ?? null),
                'status' => (string) ($row['status'] ?? ''),
                'term' => (string) ($row['term'] ?? ''),
                'payment_method' => (string) ($row['payment_method'] ?? ''),
                'customer_id' => (string) ($row['customer_id'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_email' => (string) ($row['customer_email'] ?? ''),
                'customer_phone' => $this->joinPhone($row['customer_phone_cc'] ?? null, $row['customer_phone'] ?? null),
                'country_code' => (string) ($row['country_code'] ?? ''),
                'subtotal_amount' => $this->money($row['subtotal_amount'] ?? null),
                'discount_amount' => $this->money($row['discount_amount'] ?? null),
                'tax_amount' => $this->money($row['tax_amount'] ?? null),
                'shipping_amount' => $this->money($row['shipping_amount'] ?? null),
                'total_amount' => $this->money($row['total_amount'] ?? null),
                'currency' => (string) ($row['currency'] ?? 'AED'),
            ],
            ReportType::Orders => [
                'order_id' => (string) ($row['order_id'] ?? ''),
                'order_public_id' => (string) ($row['order_public_id'] ?? ''),
                'placed_at' => $this->stringifyDateTime($row['placed_at'] ?? null),
                'status' => (string) ($row['status'] ?? ''),
                'term' => (string) ($row['term'] ?? ''),
                'payment_method' => (string) ($row['payment_method'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_email' => (string) ($row['customer_email'] ?? ''),
                'customer_phone' => $this->joinPhone($row['customer_phone_cc'] ?? null, $row['customer_phone'] ?? null),
                'country_code' => (string) ($row['country_code'] ?? ''),
                'total_amount' => $this->money($row['total_amount'] ?? null),
                'currency' => (string) ($row['currency'] ?? 'AED'),
            ],
            ReportType::Maintenance => [
                'id' => (string) ($row['id'] ?? ''),
                'public_id' => (string) ($row['public_id'] ?? ''),
                'created_at' => $this->stringifyDateTime($row['created_at'] ?? null),
                'status' => (string) ($row['status'] ?? ''),
                'priority' => (string) ($row['priority'] ?? ''),
                'issue_type' => (string) ($row['issue_type'] ?? ''),
                'customer_id' => (string) ($row['customer_id'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_email' => (string) ($row['customer_email'] ?? ''),
                'customer_phone' => $this->joinPhone($row['customer_phone_cc'] ?? null, $row['customer_phone'] ?? null),
                'machine_id' => (string) ($row['machine_id'] ?? ''),
                'serial_number' => $this->serialWithTrashed($row),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'machine_country' => (string) ($row['machine_country'] ?? ''),
                'technician_id' => (string) ($row['technician_id'] ?? ''),
                'technician_name' => (string) ($row['technician_name'] ?? ''),
                'closed_at' => $this->stringifyDateTime($row['closed_at'] ?? null),
                'resolution_hours' => $row['resolution_hours'] !== null ? (string) $row['resolution_hours'] : '',
            ],
            ReportType::Customers => [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => $this->joinPhone($row['phone_country_code'] ?? null, $row['phone'] ?? null),
                'country_code' => (string) ($row['country_code'] ?? ''),
                'is_active' => isset($row['is_active']) ? ($row['is_active'] ? 'true' : 'false') : '',
                'created_at' => $this->stringifyDateTime($row['created_at'] ?? null),
                'order_count' => (string) ($row['order_count'] ?? '0'),
                'total_spend' => $this->money($row['total_spend'] ?? null),
                'last_order_at' => $this->stringifyDateTime($row['last_order_at'] ?? null),
                'machines_count' => (string) ($row['machines_count'] ?? '0'),
            ],
        };
    }

    // ---------- Helpers ----------

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function statusFilter(array $filters): array
    {
        $value = $filters['status'] ?? null;
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $v): string => is_string($v) ? $v : '',
                $value,
            ), static fn (string $v): bool => $v !== ''));
        }

        return [];
    }

    /**
     * Best-effort currency resolution. Returns the most common country
     * currency in the filtered set, falling back to AED.
     *
     * @param  array<string, mixed>  $filters
     */
    private function resolveCurrency(array $filters): string
    {
        $country = $this->stringOrNull($filters['country'] ?? null);
        if ($country !== null) {
            $currency = DB::table('countries')->where('code', $country)->value('currency');
            if (is_string($currency) && $currency !== '') {
                return $currency;
            }
        }

        return 'AED';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function echoFilters(array $filters, ReportType $type): array
    {
        $base = [
            'date_from' => $this->stringOrNull($filters['date_from'] ?? null),
            'date_to' => $this->stringOrNull($filters['date_to'] ?? null),
        ];

        return match ($type) {
            ReportType::Sales => $base + [
                'product_id' => isset($filters['product_id']) ? (int) $filters['product_id'] : null,
                'country' => $this->stringOrNull($filters['country'] ?? null),
                'term' => $this->stringOrNull($filters['term'] ?? null),
                'payment_method' => $this->stringOrNull($filters['payment_method'] ?? null),
                'bucket' => $this->stringOrNull($filters['bucket'] ?? null),
            ],
            ReportType::Orders => $base + [
                'status' => $this->statusFilter($filters),
                'country' => $this->stringOrNull($filters['country'] ?? null),
                'payment_method' => $this->stringOrNull($filters['payment_method'] ?? null),
                'term' => $this->stringOrNull($filters['term'] ?? null),
                'bucket' => $this->stringOrNull($filters['bucket'] ?? null),
            ],
            ReportType::Maintenance => $base + [
                'status' => $this->statusFilter($filters),
                'assigned_to_user_id' => isset($filters['assigned_to_user_id']) ? (int) $filters['assigned_to_user_id'] : null,
                'issue_type' => $this->stringOrNull($filters['issue_type'] ?? null),
                'priority' => $this->stringOrNull($filters['priority'] ?? null),
                'country' => $this->stringOrNull($filters['country'] ?? null),
                'bucket' => $this->stringOrNull($filters['bucket'] ?? null),
            ],
            ReportType::Customers => $base + [
                'country' => $this->stringOrNull($filters['country'] ?? null),
                'has_orders' => $this->boolOrNull($filters['has_orders'] ?? null),
                'is_active' => $this->boolOrNull($filters['is_active'] ?? null),
                'bucket' => $this->stringOrNull($filters['bucket'] ?? null),
            ],
        };
    }

    private function mapSalesSort(?string $sort): string
    {
        return match ($sort) {
            'total_amount' => 'orders.total_amount',
            'product_code' => 'orders.id',
            default => 'orders.created_at',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'no'], true)) {
                return false;
            }
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return null;
    }

    /**
     * @param  Collection<array-key, mixed>  $counts
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function fillStatusMap($counts, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (int) ($counts[$key] ?? 0);
        }

        return $out;
    }

    private function stringifyDateTime(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }
        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function joinPhone(mixed $cc, mixed $phone): string
    {
        $cc = is_string($cc) ? $cc : '';
        $phone = is_string($phone) ? $phone : '';
        if ($phone === '') {
            return '';
        }

        return $cc.$phone;
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function serialWithTrashed(array $row): string
    {
        $serial = (string) ($row['serial_number'] ?? '');
        $deleted = $row['machine_deleted_at'] ?? null;
        if ($deleted !== null && $serial !== '') {
            return $serial.' (deleted)';
        }

        return $serial;
    }
}
