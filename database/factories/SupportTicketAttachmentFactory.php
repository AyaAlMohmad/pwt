<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FileType;
use App\Models\File;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicketAttachment>
 */
final class SupportTicketAttachmentFactory extends Factory
{
    protected $model = SupportTicketAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'support_ticket_message_id' => SupportTicketMessage::factory(),
            'file_id' => File::factory()->forType(FileType::SupportAttachment),
            'uploaded_by_user_id' => User::factory(),
        ];
    }

    public function forTicket(SupportTicket $ticket): self
    {
        return $this->state(fn (array $attrs): array => [
            'support_ticket_id' => $ticket->id,
        ]);
    }

    public function forMessage(SupportTicketMessage $message): self
    {
        return $this->state(fn (array $attrs): array => [
            'support_ticket_message_id' => $message->id,
            'support_ticket_id' => $message->support_ticket_id,
        ]);
    }

    public function forFile(File $file): self
    {
        return $this->state(fn (array $attrs): array => [
            'file_id' => $file->id,
            'uploaded_by_user_id' => $file->user_id,
        ]);
    }
}
