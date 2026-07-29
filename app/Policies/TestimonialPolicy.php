<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Testimonial;
use App\Models\User;

/**
 * Authorization policy for the {@see Testimonial} model — admin surface only.
 *
 * Customer endpoints (Phase 7) do NOT consult this policy. The policy
 * gates the admin CRUD added in Phase 20.
 *
 * Permission matrix per docs/architecture/admin-content.md § 6.4:
 *   - viewAny / view              → testimonials.view   (super_admin, sales)
 *   - create / update             → testimonials.create / .update (super_admin, sales)
 *   - delete                      → testimonials.delete + role = super_admin
 */
final class TestimonialPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('testimonials.view');
    }

    public function view(User $actor, Testimonial $testimonial): bool
    {
        return $actor->can('testimonials.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('testimonials.create');
    }

    public function update(User $actor, Testimonial $testimonial): bool
    {
        return $actor->can('testimonials.update');
    }

    /**
     * Super_admin-only delete (policy-layer enforcement).
     */
    public function delete(User $actor, Testimonial $testimonial): bool
    {
        if (! $actor->can('testimonials.delete')) {
            return false;
        }

        return $actor->hasRole(UserRole::SuperAdmin->value);
    }
}
