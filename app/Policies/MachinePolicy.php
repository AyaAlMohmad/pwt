<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Machine;
use App\Models\User;

/**
 * Ownership-only authorisation for {@see Machine} on the customer
 * surface; permission-keyed gates for the Phase 18 admin surface.
 *
 * Per CLAUDE.md § 7.3 ownership failure surfaces as **403 not 404**.
 */
final class MachinePolicy
{
    /**
     * Any authenticated customer may list their OWN machines. The
     * controller scopes the query by `user_id = auth.id`.
     */
    public function viewAny(User $actor): bool
    {
        unset($actor);

        return true;
    }

    public function view(User $actor, Machine $machine): bool
    {
        return $machine->user_id === $actor->id;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin abilities (Phase 18)
    |--------------------------------------------------------------------------
    | Permission-keyed gates for the admin-machines module. Ownership is
    | intentionally NOT checked — admins act on any customer's machine.
    | The `admin` middleware (Phase 14) is the role gate; these methods
    | layer the finer-grained Spatie permission check on top.
    |
    | See docs/architecture/admin-machines-maintenance.md § 6.
    */

    public function viewAnyAdmin(User $actor): bool
    {
        return $actor->can('machines.view');
    }

    public function viewAdmin(User $actor, Machine $machine): bool
    {
        unset($machine);

        return $actor->can('machines.view');
    }

    public function createAdmin(User $actor): bool
    {
        return $actor->can('machines.create');
    }

    public function updateAdmin(User $actor, Machine $machine): bool
    {
        unset($machine);

        return $actor->can('machines.update');
    }

    public function dueMaintenanceAdmin(User $actor): bool
    {
        return $actor->can('machines.view');
    }
}
