<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportTicketMessageSenderType;
use Database\Factories\SupportTicketMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single message within a {@see SupportTicket} thread.
 *
 * `is_internal_note=true` rows are admin-only and MUST NEVER surface to
 * the customer (docs/architecture/support.md § 6.2). The customer-side
 * service / controller / resource layers each filter independently as
 * defence-in-depth.
 *
 * @property int $id
 * @property int $support_ticket_id
 * @property SupportTicketMessageSenderType $sender_type
 * @property int|null $sender_user_id
 * @property string $body
 * @property bool $is_internal_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SupportTicket|null $ticket
 * @property-read User|null $sender
 */
final class SupportTicketMessage extends Model
{
    /** @use HasFactory<SupportTicketMessageFactory> */
    use HasFactory;

    protected $table = 'support_ticket_messages';

    /** @var list<string> */
    protected $fillable = [
        'support_ticket_id',
        'sender_type',
        'sender_user_id',
        'body',
        'is_internal_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_type' => SupportTicketMessageSenderType::class,
            'is_internal_note' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * @return HasMany<SupportTicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'support_ticket_message_id');
    }

    protected static function newFactory(): SupportTicketMessageFactory
    {
        return SupportTicketMessageFactory::new();
    }
}
