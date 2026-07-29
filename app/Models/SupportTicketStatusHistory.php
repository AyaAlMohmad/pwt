<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Support\SupportTicketService;
use Database\Factories\SupportTicketStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Append-only timeline of every status transition for a
 * {@see SupportTicket}.
 *
 * Rows are written by {@see SupportTicketService} inside the same
 * DB transaction as the corresponding `support_tickets.status`
 * mutation. The `updating` model event is blocked so a stray `->save()`
 * on a loaded row raises immediately.
 *
 * @property int $id
 * @property int $support_ticket_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $actor_type
 * @property int|null $actor_user_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read SupportTicket|null $ticket
 * @property-read User|null $actor
 */
final class SupportTicketStatusHistory extends Model
{
    /** @use HasFactory<SupportTicketStatusHistoryFactory> */
    use HasFactory;

    protected $table = 'support_ticket_status_history';

    /** @var list<string> */
    protected $fillable = [
        'support_ticket_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_user_id',
        'note',
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
     * Block any update path. Status-history rows are immutable; the
     * service writes them once and never mutates them.
     */
    protected static function booted(): void
    {
        self::updating(function (): bool {
            throw new RuntimeException(
                'SupportTicketStatusHistory rows are immutable; updates are not allowed.',
            );
        });
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function newFactory(): SupportTicketStatusHistoryFactory
    {
        return SupportTicketStatusHistoryFactory::new();
    }
}
