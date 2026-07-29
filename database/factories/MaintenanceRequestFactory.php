<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MaintenanceRequest>
 */
final class MaintenanceRequestFactory extends Factory
{
    protected $model = MaintenanceRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'machine_id' => Machine::factory(),
            'issue_type' => MaintenanceIssueType::FilterChange->value,
            'description' => fake()->sentence(20),
            'status' => MaintenanceStatus::New->value,
            'priority' => MaintenancePriority::Medium->value,
            'preferred_date' => null,
            'assigned_to_user_id' => null,
            'resolution_notes' => null,
            'closed_at' => null,
            'cancellation_reason' => null,
            'cancelled_by_user_id' => null,
        ];
    }

    public function newStatus(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => MaintenanceStatus::New->value,
            'assigned_to_user_id' => null,
            'closed_at' => null,
        ]);
    }

    public function assigned(?User $assignee = null): self
    {
        $assigneeId = $assignee !== null ? $assignee->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'status' => MaintenanceStatus::Assigned->value,
            'assigned_to_user_id' => $assigneeId,
        ]);
    }

    public function inProgress(?User $assignee = null): self
    {
        $assigneeId = $assignee !== null ? $assignee->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'status' => MaintenanceStatus::InProgress->value,
            'assigned_to_user_id' => $assigneeId,
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => MaintenanceStatus::Completed->value,
            'resolution_notes' => 'Filter cartridge replaced. Flow verified.',
            'closed_at' => now()->subHour(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => MaintenanceStatus::Cancelled->value,
            'cancellation_reason' => 'Changed my mind.',
            'closed_at' => now()->subHour(),
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attrs): array => [
            'user_id' => $user->id,
        ]);
    }

    public function forMachine(Machine $machine): self
    {
        return $this->state(fn (array $attrs): array => [
            'machine_id' => $machine->id,
            'user_id' => $machine->user_id,
        ]);
    }

    public function withPriority(MaintenancePriority $priority): self
    {
        return $this->state(fn (array $attrs): array => [
            'priority' => $priority->value,
        ]);
    }

    public function withIssueType(MaintenanceIssueType $issueType): self
    {
        return $this->state(fn (array $attrs): array => [
            'issue_type' => $issueType->value,
        ]);
    }
}
