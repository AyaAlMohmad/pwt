<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Testimonial>
 */
final class TestimonialFactory extends Factory
{
    protected $model = Testimonial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();
        $quote = fake()->sentence(12);

        return [
            'name' => $name,
            'role' => fake()->jobTitle(),
            'company' => fake()->company(),
            'avatar_url' => null,
            'rating' => 5,
            'quote' => [
                'en' => $quote,
                'ar' => $quote,
            ],
            'display_order' => 0,
            'is_active' => true,
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

    public function rating(int $rating): self
    {
        return $this->state(fn (array $attrs): array => ['rating' => $rating]);
    }

    public function softDeleted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'deleted_at' => now()->subMinute(),
        ]);
    }
}
