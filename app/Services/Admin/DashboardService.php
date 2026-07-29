<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\DashboardPeriod;
use App\Enums\MaintenanceStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Admin\Dto\DashboardSnapshotDto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22 — Admin dashboard KPI aggregator.
 *
 * Computes the landing-page snapshot in a single batch of count queries —
 * every KPI is a `COUNT(*)` or `SUM` against an indexed column, no
 * application-side row scans. The result is cached for 60 s per
 * docs/architecture/admin-audit-templates-dashboard.md § 6.5 so even a
 * 5-admin team polling every second only hits the DB once per minute.
 *
 * Cache key: `admin.dashboard.snapshot` (global, not per-user — the KPIs
 * are platform-wide).
 *
 * Time anchors: all "this month" queries use `now()->startOfMonth()` and
 * the current instant in `Asia/Dubai` (CLAUDE.md § 3). Upper bound is
 * `now()` not end-of-month, so reading the dashboard on day 5 reflects
 * 5 days of activity, not the entire month-to-date.
 */
final class DashboardService
{
    /**
     * Public entry point — cached.
     *
     * Tests can set `dashboard.cache_ttl = 0` to bypass the cache and
     * always exercise the live computation path.
     */
    public function snapshot(DashboardPeriod $period): DashboardSnapshotDto
    {
        $ttl = (int) config('dashboard.cache_ttl', 60);
        $key = (string) config('dashboard.cache_key', 'admin.dashboard.snapshot');

        if ($ttl <= 0) {
            return $this->compute($period);
        }

        /** @var DashboardSnapshotDto $dto */
        $dto = Cache::remember(
            $key.':'.$period->value,
            $ttl,
            fn (): DashboardSnapshotDto => $this->compute($period),
        );

        return $dto;
    }

    /**
     * True iff the snapshot key currently sits in cache (used by the
     * controller to populate `meta.cached`). Cheap — single Cache::has.
     */
    public function isCached(DashboardPeriod $period): bool
    {
        $ttl = (int) config('dashboard.cache_ttl', 60);
        if ($ttl <= 0) {
            return false;
        }

        $key = (string) config('dashboard.cache_key', 'admin.dashboard.snapshot');

        return Cache::has($key.':'.$period->value);
    }

    /**
     * Run the underlying aggregation. Public so tests can dodge the cache
     * entirely.
     */
    public function compute(DashboardPeriod $period): DashboardSnapshotDto
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Dubai'));
        $ranges = $this->resolvePeriodRanges($period, $now);

        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        return new DashboardSnapshotDto(
            orders: $this->ordersBlock($ranges['current'], $ranges['previous'], $now),
            revenue: $this->revenueBlock($ranges['current'], $ranges['previous']),
            maintenance: $this->maintenanceBlock($monthStart, $monthEnd, $now),
            customers: $this->customersBlock($ranges['current'], $ranges['previous']),
            machines: $this->machinesBlock($ranges['current'], $ranges['previous'], $now, $monthStart, $monthEnd),
            contactLeads: $this->contactLeadsBlock($ranges['current'], $ranges['previous']),
            generatedAt: $now,
        );
    }

    /**
     * Resolve current and previous date ranges for a given period.
     *
     * @return array{current: array{start: Carbon, end: Carbon}, previous: array{start: Carbon, end: Carbon}}
     */
    private function resolvePeriodRanges(DashboardPeriod $period, Carbon $now): array
    {
        switch ($period) {
            case DashboardPeriod::Yesterday:
                $currentStart = $now->copy()->subDay()->startOfDay();
                $currentEnd = $now->copy()->subDay()->endOfDay();
                $previousStart = $now->copy()->subDays(2)->startOfDay();
                $previousEnd = $now->copy()->subDays(2)->endOfDay();

                break;
            case DashboardPeriod::Last7Days:
                $currentStart = $now->copy()->subDays(6)->startOfDay();
                $currentEnd = $now->copy()->endOfDay();
                $previousStart = $now->copy()->subDays(13)->startOfDay();
                $previousEnd = $now->copy()->subDays(7)->endOfDay();

                break;
            case DashboardPeriod::ThisWeek:
                $currentStart = $now->copy()->startOfWeek();
                $currentEnd = $now->copy()->endOfDay();
                $previousStart = $now->copy()->subWeek()->startOfWeek();
                $previousEnd = $now->copy()->subWeek()->endOfWeek();

                break;
            case DashboardPeriod::ThisMonth:
                $currentStart = $now->copy()->startOfMonth();
                $currentEnd = $now->copy()->endOfDay();
                $previousStart = $now->copy()->subMonth()->startOfMonth();
                $previousEnd = $now->copy()->subMonth()->endOfMonth();

                break;
            case DashboardPeriod::LastMonth:
                $currentStart = $now->copy()->subMonth()->startOfMonth();
                $currentEnd = $now->copy()->subMonth()->endOfMonth();
                $previousStart = $now->copy()->subMonths(2)->startOfMonth();
                $previousEnd = $now->copy()->subMonths(2)->endOfMonth();

                break;
            case DashboardPeriod::Today:
            default:
                $currentStart = $now->copy()->startOfDay();
                $currentEnd = $now->copy()->endOfDay();
                $previousStart = $now->copy()->subDay()->startOfDay();
                $previousEnd = $now->copy()->subDay()->endOfDay();

                break;
        }

        return [
            'current' => ['start' => $currentStart, 'end' => $currentEnd],
            'previous' => ['start' => $previousStart, 'end' => $previousEnd],
        ];
    }

    /**
     * All-time counts per status + current period vs previous period volume.
     *
     * @param  array{start: Carbon, end: Carbon}  $current
     * @param  array{start: Carbon, end: Carbon}  $previous
     * @return array<string, mixed>
     */
    private function ordersBlock(array $current, array $previous, Carbon $now): array
    {
        /** @var array<int, object{status: string, c: int}> $rows */
        $rows = DB::table('orders')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) as c'))
            ->get()
            ->all();

        $counts = [];
        foreach (OrderStatus::values() as $value) {
            $counts[$value] = 0;
        }
        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row->c;
            }
        }

        $currentCount = (int) DB::table('orders')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$current['start'], $current['end']])
            ->count();

        $previousCount = (int) DB::table('orders')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$previous['start'], $previous['end']])
            ->count();

        $counts['total_this_month'] = (int) DB::table('orders')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now])
            ->count();

        return [
            'status_counts' => $counts,
            'current_period' => $currentCount,
            'previous_period' => $previousCount,
            'trend' => $currentCount - $previousCount,
        ];
    }

    /**
     * Revenue current period vs previous period — cancelled rows excluded.
     *
     * @param  array{start: Carbon, end: Carbon}  $current
     * @param  array{start: Carbon, end: Carbon}  $previous
     * @return array<string, mixed>
     */
    private function revenueBlock(array $current, array $previous): array
    {
        $currentSum = DB::table('orders')
            ->whereNull('deleted_at')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$current['start'], $current['end']])
            ->sum('total_amount');

        $previousSum = DB::table('orders')
            ->whereNull('deleted_at')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$previous['start'], $previous['end']])
            ->sum('total_amount');

        $currentFloat = (float) $currentSum;
        $previousFloat = (float) $previousSum;
        $trendPercent = $previousFloat > 0
            ? round((($currentFloat - $previousFloat) / $previousFloat) * 100, 1)
            : 0.0;

        return [
            'current_period' => number_format($currentFloat, 2, '.', ''),
            'previous_period' => number_format($previousFloat, 2, '.', ''),
            'currency' => 'AED',
            'trend_percent' => $trendPercent,
        ];
    }

    /**
     * Absolute state metrics — NOT affected by the period filter.
     *
     * @return array<string, mixed>
     */
    private function maintenanceBlock(Carbon $monthStart, Carbon $monthEnd, Carbon $now): array
    {
        $pending = (int) DB::table('maintenance_requests')
            ->whereNull('deleted_at')
            ->whereIn('status', [
                MaintenanceStatus::New->value,
                MaintenanceStatus::Assigned->value,
                MaintenanceStatus::InProgress->value,
            ])
            ->count();

        $overdue = (int) DB::table('maintenance_requests')
            ->whereNull('deleted_at')
            ->whereIn('status', [
                MaintenanceStatus::New->value,
                MaintenanceStatus::Assigned->value,
                MaintenanceStatus::InProgress->value,
            ])
            ->whereNotNull('preferred_date')
            ->where('preferred_date', '<', $now->toDateString())
            ->count();

        $dueThisMonth = (int) DB::table('machines')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('next_maintenance_due_at')
            ->whereBetween('next_maintenance_due_at', [
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ])
            ->count();

        return [
            'pending_requests' => $pending,
            'overdue' => $overdue,
            'due_this_month' => $dueThisMonth,
        ];
    }

    /**
     * Customers = users with the `customer` role. Admins are excluded.
     *
     * @param  array{start: Carbon, end: Carbon}  $current
     * @param  array{start: Carbon, end: Carbon}  $previous
     * @return array<string, mixed>
     */
    private function customersBlock(array $current, array $previous): array
    {
        $customerUserIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', UserRole::Customer->value)
            ->where('model_has_roles.model_type', User::class)
            ->pluck('model_has_roles.model_id');

        $newCurrent = (int) DB::table('users')
            ->whereNull('deleted_at')
            ->whereIn('id', $customerUserIds)
            ->whereBetween('created_at', [$current['start'], $current['end']])
            ->count();

        $newPrevious = (int) DB::table('users')
            ->whereNull('deleted_at')
            ->whereIn('id', $customerUserIds)
            ->whereBetween('created_at', [$previous['start'], $previous['end']])
            ->count();

        $totalActive = (int) DB::table('users')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('id', $customerUserIds)
            ->count();

        return [
            'new_current_period' => $newCurrent,
            'new_previous_period' => $newPrevious,
            'trend' => $newCurrent - $newPrevious,
            'total_active' => $totalActive,
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon}  $current
     * @param  array{start: Carbon, end: Carbon}  $previous
     * @return array<string, mixed>
     */
    private function machinesBlock(array $current, array $previous, Carbon $now, Carbon $monthStart, Carbon $monthEnd): array
    {
        $totalActive = (int) DB::table('machines')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->count();

        $newCurrent = (int) DB::table('machines')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereBetween('created_at', [$current['start'], $current['end']])
            ->count();

        $newPrevious = (int) DB::table('machines')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereBetween('created_at', [$previous['start'], $previous['end']])
            ->count();

        $customersCount = (int) DB::table('machines')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->distinct('user_id')
            ->count('user_id');

        $dueIn30 = (int) DB::table('machines')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('next_maintenance_due_at')
            ->where('next_maintenance_due_at', '<=', $now->copy()->addDays(30)->toDateString())
            ->count();

        return [
            'total_active' => $totalActive,
            'new_current_period' => $newCurrent,
            'new_previous_period' => $newPrevious,
            'trend' => $newCurrent - $newPrevious,
            'customers_count' => $customersCount,
            'due_maintenance_30d' => $dueIn30,
        ];
    }

    /**
     * Open (unprocessed) contact leads + new in current vs previous period.
     *
     * @param  array{start: Carbon, end: Carbon}  $current
     * @param  array{start: Carbon, end: Carbon}  $previous
     * @return array<string, mixed>
     */
    private function contactLeadsBlock(array $current, array $previous): array
    {
        $openCount = (int) DB::table('contact_leads')
            ->where('processed', false)
            ->count();

        $newCurrent = (int) DB::table('contact_leads')
            ->whereBetween('created_at', [$current['start'], $current['end']])
            ->count();

        $newPrevious = (int) DB::table('contact_leads')
            ->whereBetween('created_at', [$previous['start'], $previous['end']])
            ->count();

        return [
            'open_count' => $openCount,
            'new_current_period' => $newCurrent,
            'new_previous_period' => $newPrevious,
            'trend' => $newCurrent - $newPrevious,
        ];
    }
}
