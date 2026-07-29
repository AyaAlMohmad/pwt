<?php

declare(strict_types=1);

namespace App\Events\Orders;

use App\Events\BaseDomainEvent;
use App\Models\Order;
use App\Services\Orders\OrderService;

/**
 * Fired post-commit when {@see OrderService::create()}
 * persists a new order. The listener fans out an in-app + email
 * notification to the order owner.
 */
final class OrderCreated extends BaseDomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $userId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(Order $order): self
    {
        return new self(
            orderId: $order->id,
            userId: $order->user_id,
            actorUserId: $order->user_id,
        );
    }

    public function templateKey(): string
    {
        return 'order.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
        ];
    }
}
