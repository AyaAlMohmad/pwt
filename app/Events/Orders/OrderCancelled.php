<?php

declare(strict_types=1);

namespace App\Events\Orders;

use App\Events\BaseDomainEvent;
use App\Models\Order;

final class OrderCancelled extends BaseDomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        ?int $actorUserId = null,
        public readonly ?string $note = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(Order $order, ?int $actorUserId = null, ?string $note = null): self
    {
        return new self($order->id, $order->user_id, $actorUserId, $note);
    }

    public function templateKey(): string
    {
        return 'order.cancelled';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
            'note' => $this->note,
        ];
    }
}
