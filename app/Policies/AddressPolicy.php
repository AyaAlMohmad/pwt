<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

/**
 * Ownership-only authorisation for {@see Address}.
 *
 * Customer-side endpoints are caller-scoped: a customer may only ever
 * view / update / delete their own address rows. Per CLAUDE.md § 7.3
 * the ownership failure is a 403, not a 404 — Laravel's
 * AuthorizationException is shaped by the global handler.
 *
 * Admin-side address management (e.g. reassigning a customer's address
 * during a support call) is out of scope for this phase and will be
 * implemented under a separate policy method or admin controller in
 * Phase 16+.
 */
final class AddressPolicy
{
    /**
     * Any authenticated customer may list their OWN addresses. The
     * controller already scopes the query to `user_id = auth()->id()`,
     * so this gate simply asserts that a session exists.
     */
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Address $address): bool
    {
        return $address->user_id === $actor->id;
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function update(User $actor, Address $address): bool
    {
        return $address->user_id === $actor->id;
    }

    public function delete(User $actor, Address $address): bool
    {
        return $address->user_id === $actor->id;
    }
}
