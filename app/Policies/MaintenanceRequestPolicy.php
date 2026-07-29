<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Rules\OwnedMachine;

/**
 * Ownership-only authorisation for {@see MaintenanceRequest} on the
 * customer surface; permission-keyed gates for the Phase 18 admin
 * surface.
 *
 * Per CLAUDE.md § 7.3 ownership failure surfaces as **403 not 404**.
 */
final class MaintenanceRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        unset($actor);

        return true;
    }

    public function view(User $actor, MaintenanceRequest $request): bool
    {
        return $request->user_id === $actor->id;
    }

    /**
     * Any authenticated customer may submit a maintenance request. The
     * per-machine ownership check is handled by the
     * {@see OwnedMachine} validation rule.
     */
    public function create(User $actor): bool
    {
        unset($actor);

        return true;
    }

    public function cancel(User $actor, MaintenanceRequest $request): bool
    {
        return $request->user_id === $actor->id;
    }

    public function reschedule(User $actor, MaintenanceRequest $request): bool
    {
        return $request->user_id === $actor->id
            && ! $request->status->isTerminal();
    }

    /*
    |--------------------------------------------------------------------------
    | Admin abilities (Phase 18)
    |--------------------------------------------------------------------------
    | Permission-keyed gates for the admin-maintenance module. Ownership
    | is intentionally NOT checked — admins act on any customer's
    | request. The `admin` middleware (Phase 14) is the role gate; these
    | methods layer the finer-grained Spatie permission check on top.
    |
    | See docs/architecture/admin-machines-maintenance.md § 6.
    */

    public function viewAnyAdmin(User $actor): bool
    {
        return $actor->can('maintenance.view');
    }

    public function viewAdmin(User $actor, MaintenanceRequest $request): bool
    {
        unset($request);

        return $actor->can('maintenance.view');
    }

    public function createAdmin(User $actor): bool
    {
        return $actor->can('maintenance.create');
    }

    public function assignAdmin(User $actor, MaintenanceRequest $request): bool
    {
        unset($request);

        return $actor->can('maintenance.assign');
    }

    public function updateStatusAdmin(User $actor, MaintenanceRequest $request): bool
    {
        unset($request);

        return $actor->can('maintenance.update');
    }

    public function closeAdmin(User $actor, MaintenanceRequest $request): bool
    {
        unset($request);

        return $actor->can('maintenance.close');
    }
}
