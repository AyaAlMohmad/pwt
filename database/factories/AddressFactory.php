<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Address;
use App\Models\Country;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
final class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'country_id' => Country::factory(),
            'region_id' => null,
            'label' => fake()->randomElement(['Home', 'Office', 'Family', 'Holiday']),
            'recipient_name' => fake()->name(),
            'recipient_phone' => '501234567',
            'recipient_phone_country_code' => '+971',
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => fake()->city(),
            'area' => fake()->optional()->word(),
            'postal_code' => null,
            'notes' => null,
            'is_default' => false,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /**
     * Mark the address as the user's default. NB: the factory does NOT
     * unset any sibling defaults — tests that need a single-default
     * invariant should arrange that themselves via the service.
     */
    public function default(): self
    {
        return $this->state(fn (array $attrs): array => [
            'is_default' => true,
        ]);
    }

    /**
     * Attach a region (and ensure it belongs to the address country).
     */
    public function withRegion(): self
    {
        return $this->afterMaking(function (Address $address): void {
            if ($address->region_id !== null) {
                return;
            }
            /** @var Region $region */
            $region = Region::factory()->create(['country_id' => $address->country_id]);
            $address->region_id = $region->id;
        });
    }

    public function softDeleted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'deleted_at' => now()->subMinutes(5),
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attrs): array => [
            'user_id' => $user->id,
        ]);
    }

    public function forCountry(Country $country): self
    {
        return $this->state(fn (array $attrs): array => [
            'country_id' => $country->id,
        ]);
    }

    public function withCoordinates(float $lat = 25.2048, float $lng = 55.2708): self
    {
        return $this->state(fn (array $attrs): array => [
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }
}
