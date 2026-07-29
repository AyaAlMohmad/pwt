<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
final class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = strtolower(fake()->unique()->lexify('??'));

        return [
            'code' => $code,
            'name' => [
                'en' => fake()->country(),
                'ar' => 'دولة '.strtoupper($code),
            ],
            'phone_code' => '+'.fake()->numberBetween(100, 999),
            'currency' => strtoupper(fake()->lexify('???')),
            'vat_rate' => fake()->randomElement([0, 5, 10, 15]),
            'is_active' => true,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function withRegions(int $count = 3): self
    {
        return $this->afterCreating(function (Country $country) use ($count): void {
            RegionFactory::new()->count($count)->create([
                'country_id' => $country->id,
            ]);
        });
    }
}
