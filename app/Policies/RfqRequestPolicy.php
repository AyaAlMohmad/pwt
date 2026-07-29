<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RfqRequest;
use App\Models\User;

/**
 * Authorisation for {@see RfqRequest}.
 */
final class RfqRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        unset($actor);

        return true;
    }

    public function view(User $actor, RfqRequest $request): bool
    {
        unset($actor, $request);

        return true;
    }

    public function create(User $actor): bool
    {
        unset($actor);

        return true;
    }

    public function update(User $actor): bool
    {
        return $actor->can('rfq.update');
    }

    public function delete(User $actor, RfqRequest $request): bool
    {
        unset($actor, $request);

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin abilities
    |--------------------------------------------------------------------------
    */

    public function viewAnyAdmin(User $actor): bool
    {
        return $actor->can('rfq.view');
    }

    public function viewAdmin(User $actor, RfqRequest $request): bool
    {
        unset($request);

        return $actor->can('rfq.view');
    }

    public function updateAdmin(User $actor, ?RfqRequest $request = null): bool
    {
        unset($request);

        return $actor->can('rfq.update');
    }
}
