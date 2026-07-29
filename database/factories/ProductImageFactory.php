<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
final class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'url' => 'products/'.fake()->unique()->lexify('????').'/image.webp',
            'alt_text' => null,
            'is_primary' => false,
            'display_order' => 0,
        ];
    }

    public function primary(): self
    {
        return $this->state(fn (array $attrs): array => ['is_primary' => true]);
    }

    public function forProduct(Product $product): self
    {
        return $this->state(fn (array $attrs): array => ['product_id' => $product->id]);
    }
}
