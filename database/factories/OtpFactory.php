<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OtpPurpose;
use App\Models\Otp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Otp>
 */
final class OtpFactory extends Factory
{
    protected $model = Otp::class;

    protected static ?string $cachedHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Cache a single bcrypt hash so test suites with many factories
        // don't suffer the per-row cost.
        return [
            'public_id' => (string) Str::uuid(),
            'identifier' => '+9715'.fake()->unique()->numerify('########'),
            'purpose' => OtpPurpose::Register->value,
            'code_hash' => self::$cachedHash ??= Hash::make('123456'),
            'attempts' => 0,
            'max_attempts' => 3,
            'expires_at' => now()->addMinutes(5),
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

    public function exhausted(): self
    {
        return $this->state(fn (array $attributes): array => [
            'attempts' => 3,
            'max_attempts' => 3,
            'consumed_at' => now(),
        ]);
    }

    public function forRegister(): self
    {
        return $this->state(fn (array $attributes): array => [
            'purpose' => OtpPurpose::Register->value,
        ]);
    }

    public function forLogin(): self
    {
        return $this->state(fn (array $attributes): array => [
            'purpose' => OtpPurpose::Login->value,
        ]);
    }

    public function forReset(): self
    {
        return $this->state(fn (array $attributes): array => [
            'purpose' => OtpPurpose::Reset->value,
        ]);
    }

    /**
     * Configure the row with a known plaintext code (and matching hash).
     */
    public function withCode(string $code): self
    {
        return $this->state(fn (array $attributes): array => [
            'code_hash' => Hash::make($code),
        ]);
    }
}
