<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\NotificationTemplate;
use App\Models\User;

/**
 * Phase 22 — Notification template editor policy.
 *
 * Both list and update are super_admin only — see
 * docs/architecture/admin-audit-templates-dashboard.md § 6.2.2. No per-row
 * ownership check because templates are platform-wide (no `user_id`).
 *
 * The role check is defence-in-depth on top of the seeded
 * `notifications.templates` permission key (currently only granted to
 * super_admin via the wildcard in `RolesAndPermissionsSeeder`).
 */
final class NotificationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAuthorizedAdmin($user);
    }

    public function view(User $user, NotificationTemplate $template): bool
    {
        unset($template);

        return $this->isAuthorizedAdmin($user);
    }

    public function update(User $user, NotificationTemplate $template): bool
    {
        unset($template);

        return $this->isAuthorizedAdmin($user);
    }

    private function isAuthorizedAdmin(User $user): bool
    {
        return $user->hasPermissionTo('notifications.templates')
            && $user->hasRole(UserRole::SuperAdmin->value);
    }
}
