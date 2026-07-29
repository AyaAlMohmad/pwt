<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Providers\AppServiceProvider;

/**
 * Phase 22 — Audit-log read policy.
 *
 * Not bound to an Eloquent model — the underlying Spatie `Activity` class
 * lives in vendor space and we do not register policies against vendor
 * classes (CLAUDE.md § 7.3 convention).
 *
 * Invoked via the named gate `audit-logs.view` registered in
 * {@see AppServiceProvider::registerPolicies()}. The
 * controller calls `$this->authorize('audit-logs.view')` which delegates
 * to {@see self::viewAny()}.
 *
 * super_admin only — see
 * docs/architecture/admin-audit-templates-dashboard.md § 6.2.1. The role
 * check is defence-in-depth on top of the seeded `audit_logs.view`
 * permission key (which is currently only granted to super_admin via the
 * wildcard in `RolesAndPermissionsSeeder`).
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('audit_logs.view')
            && $user->hasRole(UserRole::SuperAdmin->value);
    }
}
