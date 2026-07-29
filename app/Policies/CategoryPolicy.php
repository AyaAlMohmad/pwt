<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;

/**
 * Authorization policy for the {@see Category} model — admin surface only.
 *
 * Customer endpoints (Phase 6) do NOT consult this policy: they rely on
 * the model scope `scopeVisibleToCustomers` to filter inactive rows. The
 * policy here gates the admin CRUD added in Phase 16.
 *
 * Permission matrix per docs/architecture/admin-catalog.md § 6.4:
 *   - viewAny / view     → categories.view  (super_admin, sales, ops, support)
 *   - create / update    → categories.create / .update (super_admin, sales)
 *   - delete             → categories.delete  + role = super_admin
 *
 * The DELETE gate is super_admin-only by orchestrator decision: even
 * though `sales` carries `categories.delete` from the seeder, the policy
 * adds a defence-in-depth role check.
 */
final class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('categories.view');
    }

    public function view(User $actor, Category $category): bool
    {
        return $actor->can('categories.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('categories.create');
    }

    public function update(User $actor, Category $category): bool
    {
        return $actor->can('categories.update');
    }

    /**
     * Super_admin-only delete (policy-layer enforcement).
     *
     * The permission must be present AND the actor must hold the
     * super_admin role. Matches the docs/architecture/admin-catalog.md
     * § 9.1 alternative (Option 2).
     */
    public function delete(User $actor, Category $category): bool
    {
        if (! $actor->can('categories.delete')) {
            return false;
        }

        return $actor->hasRole(UserRole::SuperAdmin->value);
    }
}
