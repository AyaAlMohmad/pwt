<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $en = fake()->unique()->words(2, true);
        // @phpstan-ignore-next-line — fake()->words(true) returns string.
        $en = is_string($en) ? $en : 'Category '.fake()->unique()->numberBetween(1, 9999);
        $code = Str::slug($en).'-'.fake()->unique()->numberBetween(1, 99999);

        return [
            'parent_id' => null,
            'code' => $code,
            'name' => ['en' => ucwords($en), 'ar' => ucwords($en)],
            'description' => null,
            'image_url' => null,
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attrs): array => ['is_active' => false]);
    }

    public function withDisplayOrder(int $order): self
    {
        return $this->state(fn (array $attrs): array => ['display_order' => $order]);
    }

    public function softDeleted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'deleted_at' => now()->subMinutes(5),
        ]);
    }
}
