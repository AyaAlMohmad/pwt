<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSpec;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtoupper(Str::random(3)).'-'.fake()->unique()->numberBetween(1, 99999);

        return [
            'category_id' => Category::factory(),
            'code' => $code,
            'name' => [
                'en' => $code.' Filtration Unit',
                'ar' => $code.' Filtration Unit',
            ],
            'short_description' => [
                'en' => 'PWT model '.$code.'.',
                'ar' => 'PWT model '.$code.'.',
            ],
            'description' => null,
            'benefits' => null,
            'is_active' => true,
            'is_featured' => false,
            'is_quote_only' => false,
            'stock_status' => StockStatus::InStock->value,
            'display_order' => 0,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attrs): array => ['is_active' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attrs): array => ['is_active' => false]);
    }

    public function featured(): self
    {
        return $this->state(fn (array $attrs): array => ['is_featured' => true]);
    }

    public function quoteOnly(): self
    {
        return $this->state(fn (array $attrs): array => ['is_quote_only' => true]);
    }

    public function outOfStock(): self
    {
        return $this->state(fn (array $attrs): array => [
            'stock_status' => StockStatus::OutOfStock->value,
        ]);
    }

    public function softDeleted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'deleted_at' => now()->subMinutes(5),
        ]);
    }

    public function forCategory(Category $category): self
    {
        return $this->state(fn (array $attrs): array => ['category_id' => $category->id]);
    }

    public function withImages(int $count = 3): self
    {
        return $this->afterCreating(function (Product $product) use ($count): void {
            ProductImage::factory()
                ->count($count)
                ->forProduct($product)
                ->sequence(fn ($sequence) => [
                    'is_primary' => $sequence->index === 0,
                    'display_order' => $sequence->index,
                ])
                ->create();
        });
    }

    public function withPrices(): self
    {
        return $this->afterCreating(function (Product $product): void {
            ProductPrice::factory()
                ->forProduct($product)
                ->buy(2500.00)
                ->create();

            ProductPrice::factory()
                ->forProduct($product)
                ->rent(150.00)
                ->create();
        });
    }

    public function withSpecs(int $count = 4): self
    {
        return $this->afterCreating(function (Product $product) use ($count): void {
            ProductSpec::factory()
                ->count($count)
                ->forProduct($product)
                ->sequence(fn ($sequence) => ['display_order' => $sequence->index])
                ->create();
        });
    }
}
