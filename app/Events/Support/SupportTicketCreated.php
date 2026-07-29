<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Events\BaseDomainEvent;
use App\Models\SupportTicket;

/**
 * Fired immediately after a customer submits a new support ticket.
 *
 * Carries only primitive scalars per BaseDomainEvent contract — the
 * listener re-queries fresh state. The Phase 11 dispatcher fans out to
 * the customer (in_app + email) and to the single ops inbox (email)
 * via the `_ops` template-key convention.
 *
 * See docs/architecture/support.md § 12.1.
 */
final class SupportTicketCreated extends BaseDomainEvent
{
    public function __construct(
        public readonly int $supportTicketId,
        public readonly int $userId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(SupportTicket $ticket): self
    {
        return new self(
            supportTicketId: $ticket->id,
            userId: $ticket->user_id,
            actorUserId: $ticket->user_id,
        );
    }

    public function templateKey(): string
    {
        return 'support.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'support_ticket_id' => $this->supportTicketId,
            'user_id' => $this->userId,
        ];
    }
}
