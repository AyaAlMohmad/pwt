<?php

declare(strict_types=1);

namespace App\Events\Support;

use App\Events\BaseDomainEvent;

/**
 * Fired after an admin transitions a support ticket to `resolved`.
 *
 * Carries the `resolution_summary` so the customer-facing email /
 * in-app notification can render the admin-supplied explanation.
 * See docs/architecture/admin-support.md § 10.1.
 */
final class SupportTicketResolved extends BaseDomainEvent
{
    public function __construct(
        public readonly int $supportTicketId,
        public readonly ?string $resolutionSummary,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'support.resolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'support_ticket_id' => $this->supportTicketId,
            'resolution_summary' => $this->resolutionSummary,
        ];
    }
}
