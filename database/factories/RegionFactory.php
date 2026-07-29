<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
final class RegionFactory extends Factory
{
    protected $model = Region::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'country_id' => Country::factory(),
            'code' => strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $name) ?? 'reg', 0, 3)),
            'name' => [
                'en' => $name,
                'ar' => 'منطقة '.$name,
            ],
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

    public function forCountry(Country $country): self
    {
        return $this->state(fn (array $attributes): array => [
            'country_id' => $country->id,
        ]);
    }
}
