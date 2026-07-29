<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Events\BaseDomainEvent;

/**
 * Fired after an admin transitions a support ticket to `in_progress`.
 *
 * Phase 19 does NOT seed a notification template for this key — the
 * `in_progress` state is purely internal and not surfaced to the
 * customer. The event class exists so future Phase 2 SLA-tracking and
 * analytics consumers can subscribe without an extra wiring change.
 *
 * See docs/architecture/admin-support.md § 10.1 / § 10.3.
 */
final class SupportTicketInProgress extends BaseDomainEvent
{
    public function __construct(
        public readonly int $supportTicketId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'support.in_progress';
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
