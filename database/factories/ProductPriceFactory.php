<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductTerm;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
final class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'term' => ProductTerm::Buy->value,
            'amount' => '2500.00',
            'currency' => 'AED',
            'period' => null,
            'min_term_months' => null,
            'is_active' => true,
        ];
    }

    public function buy(float $amount = 2500.00): self
    {
        return $this->state(fn (array $attrs): array => [
            'term' => ProductTerm::Buy->value,
            'amount' => number_format($amount, 2, '.', ''),
            'period' => null,
            'min_term_months' => null,
        ]);
    }

    public function rent(float $amount = 150.00, int $minMonths = 12): self
    {
        return $this->state(fn (array $attrs): array => [
            'term' => ProductTerm::Rent->value,
            'amount' => number_format($amount, 2, '.', ''),
            'period' => 'monthly',
            'min_term_months' => $minMonths,
        ]);
    }

    public function foc(): self
    {
        return $this->state(fn (array $attrs): array => [
            'term' => ProductTerm::Foc->value,
            'amount' => '0.00',
            'period' => null,
            'min_term_months' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attrs): array => ['is_active' => false]);
    }

    public function currency(string $currency): self
    {
        return $this->state(fn (array $attrs): array => ['currency' => $currency]);
    }

    public function forProduct(Product $product): self
    {
        return $this->state(fn (array $attrs): array => ['product_id' => $product->id]);
    }
}
