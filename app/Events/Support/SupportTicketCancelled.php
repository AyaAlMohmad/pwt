<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Events\BaseDomainEvent;

/**
 * Fired after a customer cancels their open support ticket.
 *
 * Carries only primitive scalars per BaseDomainEvent contract. The
 * Phase 11 dispatcher fans out to the customer (in_app confirmation)
 * and to the ops inbox (email — so triage staff can clean up their
 * queue) via the `_ops` template-key convention.
 *
 * See docs/architecture/support.md § 12.1.
 */
final class SupportTicketCancelled extends BaseDomainEvent
{
    public function __construct(
        public readonly int $supportTicketId,
        public readonly int $userId,
        public readonly ?string $reason,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'support.cancelled';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'support_ticket_id' => $this->supportTicketId,
            'user_id' => $this->userId,
            'reason' => $this->reason,
        ];
    }
}
