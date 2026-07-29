<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Exceptions\Admin\Promotions\PromotionHasRedemptionsException;
use App\Models\Promotion;
use App\Models\User;

/**
 * Authorization policy for the {@see Promotion} model — admin surface only.
 *
 * Customer endpoints (Phase 7) do NOT consult this policy. The policy
 * gates the admin CRUD added in Phase 20.
 *
 * Permission matrix per docs/architecture/admin-content.md § 6.4:
 *   - viewAny / view              → promotions.view   (super_admin, sales)
 *   - create / update             → promotions.create / .update (super_admin, sales)
 *   - delete                      → promotions.delete + role = super_admin
 */
final class PromotionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('promotions.view');
    }

    public function view(User $actor, Promotion $promotion): bool
    {
        return $actor->can('promotions.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('promotions.create');
    }

    public function update(User $actor, Promotion $promotion): bool
    {
        return $actor->can('promotions.update');
    }

    /**
     * Super_admin-only delete (policy-layer enforcement). The service
     * additionally blocks delete when the promotion has redemptions
     * via {@see PromotionHasRedemptionsException}.
     */
    public function delete(User $actor, Promotion $promotion): bool
    {
        if (! $actor->can('promotions.delete')) {
            return false;
        }

        return $actor->hasRole(UserRole::SuperAdmin->value);
    }
}
