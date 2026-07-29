<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Events\BaseDomainEvent;

/**
 * Fired after an admin assigns / re-assigns a support ticket.
 *
 * First `open -> assigned` transition notifies the customer
 * (`support.assigned` template). Re-assignment within non-terminal
 * states notifies the newly-assigned admin only
 * (`support.assigned_ops` template) — customer is NOT notified on
 * re-assignment.
 *
 * Carries only primitive scalars per BaseDomainEvent contract.
 * See docs/architecture/admin-support.md § 10.1.
 */
final class SupportTicketAssigned extends BaseDomainEvent
{
    public function __construct(
        public readonly int $supportTicketId,
        public readonly int $assignedToUserId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'support.assigned';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'support_ticket_id' => $this->supportTicketId,
            'assigned_to_user_id' => $this->assignedToUserId,
        ];
    }
}
