<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Cached bcrypt of the default factory password.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_country_code' => '+971',
            'phone' => '50'.fake()->unique()->numerify('#######'),
            'locale' => 'en',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('Password123!'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): self
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Assign an admin role on creation.
     *
     * Defaults to `super_admin` to preserve the historical behaviour
     * of `User::factory()->admin()->create()` (no-arg form used in
     * Phase 2..13 tests). Callers may pass a specific admin role
     * value — `operations`, `sales`, `support` — to seed role-scoped
     * admins for the Phase 14 admin-auth tests.
     */
    public function admin(string $role = UserRole::SuperAdmin->value): self
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->assignRole($role);
        });
    }

    public function customer(): self
    {
        return $this->afterCreating(function (User $user): void {
            $user->assignRole(UserRole::Customer->value);
        });
    }
}
