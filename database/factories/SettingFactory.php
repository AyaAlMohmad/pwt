<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, false),
            'value' => ['example' => fake()->word()],
            'is_public' => false,
        ];
    }

    public function publicSetting(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => true,
        ]);
    }

    public function privateSetting(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => false,
        ]);
    }
}
