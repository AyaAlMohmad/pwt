<?php

declare(strict_types=1);

namespace App\Events\Rfq;

use App\Events\BaseDomainEvent;

/**
 * Fired after an RFQ (guest) row is persisted.
 * Used to trigger the ops-inbox email notification.
 */
final class RfqCreated extends BaseDomainEvent
{
    public function __construct(
        public readonly int $rfqId,
    ) {
        parent::__construct(actorUserId: null);
    }

    public function templateKey(): string
    {
        return 'rfq.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'rfq_id' => $this->rfqId,
        ];
    }
}
