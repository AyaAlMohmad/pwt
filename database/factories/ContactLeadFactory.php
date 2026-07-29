<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactLeadSource;
use App\Models\ContactLead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactLead>
 */
final class ContactLeadFactory extends Factory
{
    protected $model = ContactLead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'phone_country_code' => null,
            'subject' => fake()->sentence(6),
            'message' => fake()->paragraph(3),
            'source' => ContactLeadSource::Web->value,
            'ip' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (PWT factory)',
            'processed' => false,
            'processed_by_user_id' => null,
            'processed_at' => null,
        ];
    }

    public function withPhone(): self
    {
        return $this->state(fn (array $attrs): array => [
            'phone' => '+971501234567',
            'phone_country_code' => '+971',
        ]);
    }

    public function processed(?User $admin = null): self
    {
        $adminId = $admin !== null ? $admin->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'processed' => true,
            'processed_by_user_id' => $adminId,
            'processed_at' => now(),
        ]);
    }

    public function mobile(): self
    {
        return $this->state(fn (array $attrs): array => [
            'source' => ContactLeadSource::Mobile->value,
        ]);
    }
}
