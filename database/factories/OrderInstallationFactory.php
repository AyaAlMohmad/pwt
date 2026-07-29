<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstallationStatus;
use App\Models\Order;
use App\Models\OrderInstallation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderInstallation>
 */
final class OrderInstallationFactory extends Factory
{
    protected $model = OrderInstallation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'scheduled_for_at' => now()->addDays(3),
            'technician_user_id' => null,
            'installation_notes' => null,
            'address_snapshot' => null,
            'status' => InstallationStatus::Scheduled->value,
            'completed_at' => null,
        ];
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (array $attrs): array => [
            'order_id' => $order->id,
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => InstallationStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }
}
