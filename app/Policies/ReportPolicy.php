<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ReportType;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Admin\Reports\AdminReportsController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Policy gating Phase 21 admin reports + exports.
 *
 * Unlike CRUD modules, reports are not tied to a single Eloquent model;
 * this policy is invoked via `Gate::define()`-style class-method calls
 * from {@see AdminReportsController}.
 *
 *  - `view(User, ReportType)` — required to read a report.
 *  - `export(User, ReportType)` — required to stream the same report.
 *
 * Permission keys (`reports.view`, `reports.export`) are seeded against
 * the operations, sales, and support roles in
 * {@see RolesAndPermissionsSeeder} (Phase 21 amend).
 *
 * Role-to-type matrix per docs/architecture/admin-reports.md § 6.4:
 *   - super_admin / operations / sales: any type
 *   - support: maintenance + customers only
 *
 * Customer-role tokens are rejected upstream by the `admin` middleware
 * (Phase 14) before this policy runs, so `view()` returns false on the
 * default match arm rather than special-casing the customer role here.
 */
final class ReportPolicy
{
    public function view(User $user, ReportType $type): bool
    {
        // Spatie's `hasPermissionTo()` is used here instead of `$user->can()`
        // because the catalog permission key (`reports.view`) is intentionally
        // distinct from the named gate (`reports.access`) the controller calls.
        // Using `can()` would still work, but reading directly off the
        // permission table makes the contract explicit and side-steps any
        // future Gate::define collision. See PROD-ISSUE-21-1.
        if (! $user->hasPermissionTo('reports.view')) {
            return false;
        }

        return $this->allowsType($user, $type);
    }

    public function export(User $user, ReportType $type): bool
    {
        if (! $user->hasPermissionTo('reports.export')) {
            return false;
        }

        return $this->allowsType($user, $type);
    }

    private function allowsType(User $user, ReportType $type): bool
    {
        return match ($type) {
            ReportType::Sales, ReportType::Orders => $user->hasAnyRole([
                UserRole::SuperAdmin->value,
                UserRole::Operations->value,
                UserRole::Sales->value,
            ]),
            ReportType::Maintenance, ReportType::Customers => $user->hasAnyRole([
                UserRole::SuperAdmin->value,
                UserRole::Operations->value,
                UserRole::Sales->value,
                UserRole::Support->value,
            ]),
        };
    }
}
