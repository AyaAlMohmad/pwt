<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ResetChannel;
use App\Models\PasswordReset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PasswordReset>
 */
final class PasswordResetFactory extends Factory
{
    protected $model = PasswordReset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'channel' => ResetChannel::Email->value,
            'identifier' => fake()->unique()->safeEmail(),
            'token_hash' => Hash::make(str_repeat('a', 64)),
            'expires_at' => now()->addHour(),
            'consumed_at' => null,
            'ip' => fake()->ipv4(),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'consumed_at' => now(),
        ]);
    }

    public function emailChannel(): self
    {
        return $this->state(fn (array $attributes): array => [
            'channel' => ResetChannel::Email->value,
            'identifier' => fake()->unique()->safeEmail(),
            'token_hash' => Hash::make(str_repeat('b', 64)),
        ]);
    }

    public function phoneChannel(): self
    {
        return $this->state(fn (array $attributes): array => [
            'channel' => ResetChannel::Phone->value,
            'identifier' => '+9715'.fake()->unique()->numerify('########'),
            'token_hash' => null,
        ]);
    }
}
