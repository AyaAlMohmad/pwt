<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SupportTicketMessageSenderType;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicketMessage>
 */
final class SupportTicketMessageFactory extends Factory
{
    protected $model = SupportTicketMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'sender_type' => SupportTicketMessageSenderType::Customer->value,
            'sender_user_id' => User::factory(),
            'body' => fake()->paragraph(),
            'is_internal_note' => false,
        ];
    }

    public function fromCustomer(?User $user = null): self
    {
        $senderId = $user !== null ? $user->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'sender_type' => SupportTicketMessageSenderType::Customer->value,
            'sender_user_id' => $senderId,
            'is_internal_note' => false,
        ]);
    }

    public function fromAdmin(?User $admin = null): self
    {
        $senderId = $admin !== null ? $admin->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'sender_type' => SupportTicketMessageSenderType::Admin->value,
            'sender_user_id' => $senderId,
            'is_internal_note' => false,
        ]);
    }

    public function fromSystem(): self
    {
        return $this->state(fn (array $attrs): array => [
            'sender_type' => SupportTicketMessageSenderType::System->value,
            'sender_user_id' => null,
            'is_internal_note' => false,
        ]);
    }

    public function internalNote(?User $admin = null): self
    {
        $senderId = $admin !== null ? $admin->id : User::factory();

        return $this->state(fn (array $attrs): array => [
            'sender_type' => SupportTicketMessageSenderType::Admin->value,
            'sender_user_id' => $senderId,
            'is_internal_note' => true,
        ]);
    }

    public function forTicket(SupportTicket $ticket): self
    {
        return $this->state(fn (array $attrs): array => [
            'support_ticket_id' => $ticket->id,
        ]);
    }
}
