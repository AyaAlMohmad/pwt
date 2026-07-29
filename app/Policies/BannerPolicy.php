<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Banner;
use App\Models\User;

/**
 * Authorization policy for the {@see Banner} model — admin surface only.
 *
 * Customer endpoints (Phase 7) do NOT consult this policy: they read
 * directly via the model `scopeActive` filter. The policy gates the admin
 * CRUD added in Phase 20.
 *
 * Permission matrix per docs/architecture/admin-content.md § 6.4:
 *   - viewAny / view              → banners.view   (super_admin, sales)
 *   - create / update             → banners.create / .update (super_admin, sales)
 *   - delete                      → banners.delete + role = super_admin
 *
 * DELETE is super_admin-only at the policy layer (orchestrator decision)
 * even though `sales` carries `banners.delete` from the seeder.
 */
final class BannerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('banners.view');
    }

    public function view(User $actor, Banner $banner): bool
    {
        return $actor->can('banners.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('banners.create');
    }

    public function update(User $actor, Banner $banner): bool
    {
        return $actor->can('banners.update');
    }

    /**
     * Super_admin-only delete (policy-layer enforcement).
     */
    public function delete(User $actor, Banner $banner): bool
    {
        if (! $actor->can('banners.delete')) {
            return false;
        }

        return $actor->hasRole(UserRole::SuperAdmin->value);
    }
}
