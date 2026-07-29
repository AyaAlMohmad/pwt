<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicketStatusHistory>
 */
final class SupportTicketStatusHistoryFactory extends Factory
{
    protected $model = SupportTicketStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'from_status' => null,
            'to_status' => SupportTicketStatus::Open->value,
            'actor_type' => 'customer',
            'actor_user_id' => User::factory(),
            'note' => null,
        ];
    }

    public function forTicket(SupportTicket $ticket): self
    {
        return $this->state(fn (array $attrs): array => [
            'support_ticket_id' => $ticket->id,
        ]);
    }

    public function transition(?SupportTicketStatus $from, SupportTicketStatus $to): self
    {
        return $this->state(fn (array $attrs): array => [
            'from_status' => $from?->value,
            'to_status' => $to->value,
        ]);
    }
}
