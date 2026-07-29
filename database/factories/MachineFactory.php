<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MachineSource;
use App\Enums\ProductTerm;
use App\Models\Address;
use App\Models\Machine;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Machine>
 */
final class MachineFactory extends Factory
{
    protected $model = Machine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'address_id' => Address::factory(),
            'product_id' => Product::factory(),
            'serial_number' => 'TEST-'.strtoupper(Str::random(10)),
            'term' => ProductTerm::Buy->value,
            'installed_at' => now()->subMonths(6)->toDateString(),
            'filter_change_interval_months' => 6,
            'next_maintenance_due_at' => now()->toDateString(),
            'location_label' => fake()->company(),
            'country_code' => 'ae',
            'notes' => null,
            'is_active' => true,
            'individual_buy_enabled' => true,
            'source' => MachineSource::Imported->value,
            'order_id' => null,
            'order_installation_id' => null,
        ];
    }

    public function imported(): self
    {
        return $this->state(fn (array $attrs): array => [
            'source' => MachineSource::Imported->value,
            'order_id' => null,
            'order_installation_id' => null,
        ]);
    }

    public function fromOrder(Order $order): self
    {
        return $this->state(fn (array $attrs): array => [
            'source' => MachineSource::Created->value,
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'address_id' => $order->delivery_address_id,
            'serial_number' => 'PENDING-'.$order->public_id.'-00-00',
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attrs): array => [
            'is_active' => false,
        ]);
    }

    public function dueSoon(): self
    {
        return $this->state(fn (array $attrs): array => [
            'next_maintenance_due_at' => now()->addDays(7)->toDateString(),
        ]);
    }

    public function overdue(): self
    {
        return $this->state(fn (array $attrs): array => [
            'next_maintenance_due_at' => now()->subDays(7)->toDateString(),
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attrs): array => [
            'user_id' => $user->id,
        ]);
    }

    public function forProduct(Product $product): self
    {
        return $this->state(fn (array $attrs): array => [
            'product_id' => $product->id,
        ]);
    }
}
