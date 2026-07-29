<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductTerm;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'delivery_address_id' => Address::factory(),
            'billing_address_id' => null,
            'promotion_id' => null,
            'term' => ProductTerm::Buy->value,
            'payment_method' => PaymentMethod::Card->value,
            'status' => OrderStatus::Pending->value,
            'currency' => 'AED',
            'subtotal_amount' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'tax_rate' => '5.00',
            'shipping_amount' => '0.00',
            'total_amount' => '0.00',
            'idempotency_key' => null,
            'idempotency_request_hash' => null,
            'notes' => null,
            'cancellation_reason' => null,
            'confirmed_at' => null,
            'scheduled_for_at' => null,
            'delivered_at' => null,
            'installed_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => OrderStatus::Pending->value,
            'confirmed_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function confirmed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Changed my mind',
        ]);
    }

    public function installed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => OrderStatus::Installed->value,
            'confirmed_at' => now()->subDays(5),
            'scheduled_for_at' => now()->subDays(3),
            'installed_at' => now()->subDay(),
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => OrderStatus::Completed->value,
            'confirmed_at' => now()->subDays(10),
            'scheduled_for_at' => now()->subDays(7),
            'installed_at' => now()->subDays(2),
            'completed_at' => now(),
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attrs): array => [
            'user_id' => $user->id,
        ]);
    }

    public function forAddress(Address $address): self
    {
        return $this->state(fn (array $attrs): array => [
            'delivery_address_id' => $address->id,
        ]);
    }

    public function withPromotion(Promotion $promo, float $discount = 100.00): self
    {
        return $this->state(fn (array $attrs): array => [
            'promotion_id' => $promo->id,
            'discount_amount' => number_format($discount, 2, '.', ''),
        ]);
    }

    /**
     * Attach $count line items after the order is created, using $product
     * if provided, otherwise a freshly-created product.
     */
    public function withItems(int $count = 1, ?Product $product = null): self
    {
        return $this->afterCreating(function (Order $order) use ($count, $product): void {
            for ($i = 0; $i < $count; $i++) {
                OrderItem::factory()
                    ->forOrder($order)
                    ->when($product !== null, fn ($f) => $f->forProduct($product))
                    ->create();
            }
        });
    }
}
