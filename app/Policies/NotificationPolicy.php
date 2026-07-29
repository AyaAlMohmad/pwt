<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * Ownership-only authorisation for {@see Notification}.
 *
 * Per CLAUDE.md § 7.3 + orchestrator decision 1, ownership failure
 * surfaces as **403 not 404**. The customer cannot enumerate other
 * users' notification ids because the inbox is scoped to their own
 * `user_id` — 403 does not leak existence.
 *
 * See docs/architecture/notifications.md § 8.
 */
final class NotificationPolicy
{
    /**
     * Anyone authenticated may list their OWN inbox. The controller
     * scopes the query to `user_id = auth.id`.
     */
    public function viewAny(User $actor): bool
    {
        unset($actor);

        return true;
    }

    public function view(User $actor, Notification $notification): bool
    {
        return $notification->user_id === $actor->id;
    }

    public function update(User $actor, Notification $notification): bool
    {
        return $notification->user_id === $actor->id;
    }
}
