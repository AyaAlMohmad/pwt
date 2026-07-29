<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Events\BaseDomainEvent;

/**
 * Fired after an admin transitions a support ticket to `closed`
 * (terminal). See docs/architecture/admin-support.md § 10.1.
 */
final class SupportTicketClosed extends BaseDomainEvent
{
    public function __construct(
        public readonly int $supportTicketId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'support.closed';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'support_ticket_id' => $this->supportTicketId,
        ];
    }
}
