<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductTerm;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = 1000.00;
        $quantity = 1;
        $lineSubtotal = $unitPrice * $quantity;

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_code' => 'PW50M',
            'product_name_snapshot' => ['en' => 'PW50M', 'ar' => 'بي دبليو ٥٠ إم'],
            'term' => ProductTerm::Buy->value,
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'quantity' => $quantity,
            'currency' => 'AED',
            'line_subtotal' => number_format($lineSubtotal, 2, '.', ''),
            'line_discount' => '0.00',
            'line_tax' => '0.00',
            'line_total' => number_format($lineSubtotal, 2, '.', ''),
            'rent_period' => null,
            'rent_min_term_months' => null,
        ];
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (array $attrs): array => [
            'order_id' => $order->id,
            'currency' => $order->currency,
        ]);
    }

    public function forProduct(Product $product): self
    {
        $name = is_array($product->getTranslations('name'))
            ? $product->getTranslations('name')
            : ['en' => (string) $product->name, 'ar' => (string) $product->name];

        return $this->state(fn (array $attrs): array => [
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name_snapshot' => $name,
        ]);
    }

    public function withUnitPrice(float $unitPrice, int $quantity = 1, string $currency = 'AED'): self
    {
        $sub = $unitPrice * $quantity;

        return $this->state(fn (array $attrs): array => [
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'quantity' => $quantity,
            'currency' => $currency,
            'line_subtotal' => number_format($sub, 2, '.', ''),
            'line_total' => number_format($sub, 2, '.', ''),
        ]);
    }
}
