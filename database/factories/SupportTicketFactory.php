<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\Machine;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportTicket>
 */
final class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'related_order_id' => null,
            'related_machine_id' => null,
            'subject' => fake()->sentence(6),
            'category' => SupportTicketCategory::Other->value,
            'priority' => SupportTicketPriority::Medium->value,
            'status' => SupportTicketStatus::Open->value,
            'assigned_to_user_id' => null,
            'resolution_summary' => null,
            'opened_at' => now(),
            'first_response_at' => null,
            'closed_at' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason' => null,
        ];
    }

    public function open(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => SupportTicketStatus::Open->value,
            'assigned_to_user_id' => null,
            'first_response_at' => null,
            'closed_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function assigned(?User $assignee = null): self
    {
        $assigneeId = $assignee !== null ? $assignee->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'status' => SupportTicketStatus::Assigned->value,
            'assigned_to_user_id' => $assigneeId,
            'first_response_at' => now(),
        ]);
    }

    public function inProgress(?User $assignee = null): self
    {
        $assigneeId = $assignee !== null ? $assignee->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'status' => SupportTicketStatus::InProgress->value,
            'assigned_to_user_id' => $assigneeId,
            'first_response_at' => now()->subHour(),
        ]);
    }

    public function resolved(?User $assignee = null): self
    {
        $assigneeId = $assignee !== null ? $assignee->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'status' => SupportTicketStatus::Resolved->value,
            'assigned_to_user_id' => $assigneeId,
            'first_response_at' => now()->subHour(),
            'resolution_summary' => 'Issue resolved.',
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => SupportTicketStatus::Closed->value,
            'first_response_at' => now()->subDay(),
            'closed_at' => now(),
            'resolution_summary' => 'Resolved and closed.',
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => SupportTicketStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Changed my mind.',
        ]);
    }

    public function withCategory(SupportTicketCategory $category): self
    {
        return $this->state(fn (array $attrs): array => [
            'category' => $category->value,
        ]);
    }

    public function withPriority(SupportTicketPriority $priority): self
    {
        return $this->state(fn (array $attrs): array => [
            'priority' => $priority->value,
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attrs): array => [
            'user_id' => $user->id,
        ]);
    }

    public function withOrder(?Order $order = null): self
    {
        $orderId = $order !== null ? $order->id : Order::factory();

        return $this->state(fn (array $attrs): array => [
            'related_order_id' => $orderId,
            'category' => SupportTicketCategory::OrderIssue->value,
        ]);
    }

    public function withMachine(?Machine $machine = null): self
    {
        $machineId = $machine !== null ? $machine->id : Machine::factory();

        return $this->state(fn (array $attrs): array => [
            'related_machine_id' => $machineId,
            'category' => SupportTicketCategory::MaintenanceIssue->value,
        ]);
    }
}
