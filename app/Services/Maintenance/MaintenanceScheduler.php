<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\Enums\MaintenanceLogStatus;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Computes the next maintenance due date for a {@see Machine} and
 * exposes the "due within X days" query helper used by the
 * `needs_maintenance` filter.
 *
 * Formula (per docs/architecture/machines-maintenance.md § 11.1):
 *
 *   baseline = max(installed_at, latest_completed_log.completed_at)
 *   next_due = baseline + filter_change_interval_months months
 *
 * Returns `null` when the machine has no `filter_change_interval_months`
 * — used for runtime-created (post-install) machines before an admin
 * sets the interval in Phase 18.
 */
final class MaintenanceScheduler
{
    /**
     * Compute the next maintenance due date for a machine.
     *
     * Caller is responsible for persisting the result (typically via
     * `$machine->update(['next_maintenance_due_at' => $result?->toDateString()])`).
     *
     * @param  Carbon|null  $referenceFrom  If supplied, overrides the
     *                                      baseline computation — useful
     *                                      for "what if scheduled for X"
     *                                      projections in Phase 18.
     */
    public function computeNextDue(Machine $machine, ?Carbon $referenceFrom = null): ?Carbon
    {
        $interval = $machine->filter_change_interval_months;
        if ($interval === null || $interval <= 0) {
            return null;
        }

        if ($referenceFrom !== null) {
            return $referenceFrom->copy()->addMonths($interval);
        }

        $installedAt = $machine->installed_at;

        $latestCompletedAt = $machine
            ->maintenanceLogs()
            ->where('status', MaintenanceLogStatus::Completed->value)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->value('completed_at');

        // `value()` returns the column raw (no cast) — wrap with Carbon::parse
        // to stay defensive across MySQL date / datetime variants.
        $baseline = $latestCompletedAt !== null
            ? Carbon::parse((string) $latestCompletedAt)
            : $installedAt->copy();

        if ($installedAt->greaterThan($baseline)) {
            $baseline = $installedAt->copy();
        }

        return $baseline->copy()->addMonths($interval);
    }

    /**
     * Eloquent builder for machines whose next maintenance is due within
     * `$days` from NOW (overdue rows included), restricted to active +
     * non-soft-deleted.
     *
     * Used by:
     *   - The customer `needs_maintenance=true` filter on `/me/machines`.
     *   - The Phase 18 admin "due this week" dashboard widget.
     *
     * @return Builder<Machine>
     */
    public function dueWithinDays(int $days): Builder
    {
        return Machine::query()
            ->active()
            ->dueWithinDays($days);
    }
}
