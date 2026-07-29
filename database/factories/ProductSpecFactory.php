<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSpec>
 */
final class ProductSpecFactory extends Factory
{
    protected $model = ProductSpec::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $labels = [
            ['en' => 'Filtration stages', 'ar' => 'مراحل الترشيح'],
            ['en' => 'Flow rate', 'ar' => 'معدل التدفق'],
            ['en' => 'Dimensions', 'ar' => 'الأبعاد'],
            ['en' => 'Warranty', 'ar' => 'الضمان'],
        ];
        $label = fake()->randomElement($labels);

        return [
            'product_id' => Product::factory(),
            'label' => $label,
            'value' => ['en' => (string) fake()->numberBetween(1, 9), 'ar' => (string) fake()->numberBetween(1, 9)],
            'display_order' => 0,
        ];
    }

    public function forProduct(Product $product): self
    {
        return $this->state(fn (array $attrs): array => ['product_id' => $product->id]);
    }
}
