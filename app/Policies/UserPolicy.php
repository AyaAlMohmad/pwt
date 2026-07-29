<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Authorization policy for the {@see User} model.
 *
 * Two surfaces are gated through this single policy:
 *
 *  - Customer surface — `view / update / delete` reduce to a strict
 *    "same id" self-only check, used by the `/me` endpoints.
 *  - Admin surface — `viewAny / viewAdmin / updateAdmin / changeRole /
 *    deactivate` gate the admin-users lifecycle endpoints. These methods
 *    consult Spatie permission keys AND, for the privileged write paths,
 *    an additional super_admin role guard.
 *
 * See docs/architecture/admin-users.md § 6.2.
 */
final class UserPolicy
{
    public function view(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin abilities (Phase 15)
    |--------------------------------------------------------------------------
    | The following methods are consumed by AdminUsersController. The
    | customer methods above stay untouched — Laravel resolves ability
    | names case-sensitively so there is no overlap.
    */

    /**
     * May create a new user via POST /api/v1/admin/users.
     *
     * Granted to any admin holding `users.create`.
     */
    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    /**
     * May list users in the admin /users index.
     *
     * Granted to any admin holding `users.view`
     * (super_admin + support per the seeder).
     */
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    /**
     * May view a single user's admin detail.
     *
     * Same gate as viewAny — admin lifecycle is intentionally global
     * (no per-row ownership in the admin surface).
     */
    public function viewAdmin(User $actor, User $target): bool
    {
        return $actor->can('users.view');
    }

    /**
     * May apply a PATCH update to the target user.
     *
     * Requires the caller to hold `users.update` (super_admin only per
     * the seeder). A defensive role guard additionally blocks a
     * non-super_admin from modifying a super_admin target — kept for
     * forward-compat if the permission catalogue ever grants
     * `users.update` to a lower-privilege role.
     */
    public function updateAdmin(User $actor, User $target): bool
    {
        if (! $actor->can('users.update')) {
            return false;
        }

        if (
            $target->hasRole(UserRole::SuperAdmin->value)
            && ! $actor->hasRole(UserRole::SuperAdmin->value)
        ) {
            return false;
        }

        return true;
    }

    /**
     * May change another user's role via the `role` field on PATCH.
     *
     * Stricter than `updateAdmin` — only super_admin may change roles
     * even if a future seeder grant gives `users.update` to another
     * role. This is the one place where the policy gates beyond the
     * permission catalogue (per the architecture spec § 6.2).
     */
    public function changeRole(User $actor, User $target): bool
    {
        return $actor->hasRole(UserRole::SuperAdmin->value);
    }

    /**
     * May soft-deactivate the target user via DELETE.
     *
     * Requires `users.deactivate` (super_admin only). The same
     * super_admin-target defensive guard as `updateAdmin` applies.
     */
    public function deactivate(User $actor, User $target): bool
    {
        if (! $actor->can('users.deactivate')) {
            return false;
        }

        if (
            $target->hasRole(UserRole::SuperAdmin->value)
            && ! $actor->hasRole(UserRole::SuperAdmin->value)
        ) {
            return false;
        }

        return true;
    }
}
