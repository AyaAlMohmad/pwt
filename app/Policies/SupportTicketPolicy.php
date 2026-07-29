<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use App\Rules\OwnedFile;
use App\Rules\OwnedMachine;
use App\Rules\OwnedOrder;

/**
 * Authorisation for {@see SupportTicket}.
 *
 * Customer surface (Phase 12): pure ownership — `view` / `cancel`
 * succeed only when `ticket.user_id === actor.id`. Per CLAUDE.md § 7.3,
 * ownership failure surfaces as **403 not 404**.
 *
 * Admin surface (Phase 19): permission-key based via
 * `support.view` / `support.update`. The admin middleware (Phase 14)
 * has already filtered the actor down to an admin token before these
 * methods run; the policy is the per-action permission check.
 *
 * Not `final` so the customer-side methods remain mockable in test
 * subclasses without forcing a wholesale split.
 */
class SupportTicketPolicy
{
    public function viewAny(User $actor): bool
    {
        unset($actor);

        // Controller scopes the query to `user_id = auth.id`; the policy
        // simply gates access to the list endpoint.
        return true;
    }

    public function view(User $actor, SupportTicket $ticket): bool
    {
        return $ticket->user_id === $actor->id;
    }

    /**
     * Any authenticated customer may create a support ticket. The
     * per-related-resource ownership check is handled by the
     * {@see OwnedOrder} / {@see OwnedMachine}
     * / {@see OwnedFile} validation rules.
     */
    public function create(User $actor): bool
    {
        unset($actor);

        return true;
    }

    /**
     * The Policy only enforces ownership. The state-machine check
     * (status must be `open`) is the service's job and surfaces as 409.
     */
    public function cancel(User $actor, SupportTicket $ticket): bool
    {
        return $ticket->user_id === $actor->id;
    }

    /**
     * Phase 19 — admin list endpoint. Permission-key gated.
     *
     * `support.view` is granted to `super_admin`, `support`, and (for
     * cross-context awareness) `operations`. Sales is denied entirely.
     */
    public function viewAnyAdmin(User $actor): bool
    {
        return $actor->can('support.view');
    }

    /**
     * Phase 19 — admin show endpoint. Permission-key gated.
     *
     * Soft-deleted tickets remain viewable for audit purposes; the
     * service layer separately rejects write operations against trashed
     * rows with 409 `ERR_RESOURCE_TRASHED`.
     */
    public function viewAdmin(User $actor, SupportTicket $ticket): bool
    {
        unset($ticket);

        return $actor->can('support.view');
    }

    /**
     * Phase 19 — admin update (status / priority / assignment).
     *
     * `support.update` is granted to `super_admin` and `support` only.
     * `operations` and `sales` receive 403 from this gate.
     */
    public function updateAdmin(User $actor, SupportTicket $ticket): bool
    {
        unset($ticket);

        return $actor->can('support.update');
    }

    /**
     * Phase 19 — admin reply (customer message or internal note).
     *
     * Collapsed to `support.update` (the broader gate) since both keys
     * are granted to the same role set. The `support.reply` permission
     * remains declared in the seeder for forward-compat (open Q § 9.5).
     */
    public function replyAdmin(User $actor, SupportTicket $ticket): bool
    {
        unset($ticket);

        return $actor->can('support.update');
    }
}
