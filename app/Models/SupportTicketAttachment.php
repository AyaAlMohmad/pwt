<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupportTicketAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot-ish join between a Phase 4 {@see File} and a
 * {@see SupportTicket} / {@see SupportTicketMessage}.
 *
 * Immutable — no `updated_at`. UNIQUE(file_id) at the DB level enforces
 * the "one-shot attachment" invariant: the same file row cannot be
 * referenced by two attachment rows.
 *
 * @property int $id
 * @property int $support_ticket_id
 * @property int|null $support_ticket_message_id
 * @property int $file_id
 * @property int $uploaded_by_user_id
 * @property Carbon $created_at
 * @property-read SupportTicket|null $ticket
 * @property-read SupportTicketMessage|null $message
 * @property-read File|null $file
 * @property-read User|null $uploadedBy
 */
final class SupportTicketAttachment extends Model
{
    /** @use HasFactory<SupportTicketAttachmentFactory> */
    use HasFactory;

    protected $table = 'support_ticket_attachments';

    /** @var list<string> */
    protected $fillable = [
        'support_ticket_id',
        'support_ticket_message_id',
        'file_id',
        'uploaded_by_user_id',
    ];

    /**
     * Append-only — no `updated_at` column.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
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
     * @return BelongsTo<SupportTicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    protected static function newFactory(): SupportTicketAttachmentFactory
    {
        return SupportTicketAttachmentFactory::new();
    }
}
