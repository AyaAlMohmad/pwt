<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
final class OrderStatusHistoryFactory extends Factory
{
    protected $model = OrderStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'actor_type' => OrderActorType::Customer->value,
            'actor_id' => null,
            'note' => null,
            'created_at' => now(),
        ];
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (array $attrs): array => [
            'order_id' => $order->id,
        ]);
    }

    public function transition(OrderStatus $from, OrderStatus $to): self
    {
        return $this->state(fn (array $attrs): array => [
            'from_status' => $from->value,
            'to_status' => $to->value,
        ]);
    }

    public function byCustomer(?User $user = null): self
    {
        return $this->state(fn (array $attrs): array => [
            'actor_type' => OrderActorType::Customer->value,
            'actor_id' => $user?->id,
        ]);
    }

    public function bySystem(): self
    {
        return $this->state(fn (array $attrs): array => [
            'actor_type' => OrderActorType::System->value,
            'actor_id' => null,
        ]);
    }

    public function byAdmin(User $admin): self
    {
        return $this->state(fn (array $attrs): array => [
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
        ]);
    }
}
