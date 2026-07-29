<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Events\BaseDomainEvent;

/**
 * Fired after an admin posts a reply on a support ticket.
 *
 * When `$isInternal === true` the listener fan-out is a no-op
 * (no customer notification, no ops notification). The event is still
 * dispatched for activity-log / audit consistency. See
 * docs/architecture/admin-support.md § 10.6.
 */
final class SupportTicketReplied extends BaseDomainEvent
{
    public function __construct(
        public readonly int $supportTicketId,
        public readonly int $messageId,
        public readonly bool $isInternal,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'support.replied';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'support_ticket_id' => $this->supportTicketId,
            'message_id' => $this->messageId,
            'is_internal' => $this->isInternal,
        ];
    }
}
