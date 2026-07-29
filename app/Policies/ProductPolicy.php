<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;

/**
 * Authorization policy for the {@see Product} model — admin surface only.
 *
 * The customer surface (Phase 6) does NOT call into this policy. The
 * admin surface (Phase 16) calls it from every action method.
 *
 * Permission matrix per docs/architecture/admin-catalog.md § 6.4:
 *   - viewAny / view              → products.view (super_admin, sales, ops, support)
 *   - create / update             → products.create / .update (super_admin, sales)
 *   - delete                      → products.deactivate + role = super_admin
 *   - updateStatus                → products.update
 *   - manageImages                → products.manage-images
 *
 * DELETE is super_admin-only at the policy layer (orchestrator decision)
 * even though `sales` carries `products.deactivate` from the seeder.
 */
final class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('products.view');
    }

    public function view(User $actor, Product $product): bool
    {
        return $actor->can('products.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('products.create');
    }

    public function update(User $actor, Product $product): bool
    {
        return $actor->can('products.update');
    }

    /**
     * Super_admin-only delete (policy-layer enforcement).
     */
    public function delete(User $actor, Product $product): bool
    {
        if (! $actor->can('products.deactivate')) {
            return false;
        }

        return $actor->hasRole(UserRole::SuperAdmin->value);
    }

    public function updateStatus(User $actor, Product $product): bool
    {
        return $actor->can('products.update');
    }

    public function manageImages(User $actor, Product $product): bool
    {
        return $actor->can('products.manage-images');
    }
}
