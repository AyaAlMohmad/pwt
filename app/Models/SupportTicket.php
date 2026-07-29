<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Customer-submitted support ticket.
 *
 * State machine: open -> assigned -> in_progress -> resolved -> closed
 * (or cancelled from any non-terminal state). Phase 12 (customer-facing)
 * only exposes `(none) -> open` and `open -> cancelled`. See
 * docs/architecture/support.md § 2.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $related_order_id
 * @property int|null $related_machine_id
 * @property string $subject
 * @property SupportTicketCategory $category
 * @property SupportTicketPriority $priority
 * @property SupportTicketStatus $status
 * @property int|null $assigned_to_user_id
 * @property string|null $resolution_summary
 * @property Carbon $opened_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_customer_view_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Order|null $relatedOrder
 * @property-read Machine|null $relatedMachine
 * @property-read User|null $assignedTo
 * @property-read User|null $cancelledBy
 *
 * @method static Builder<static> forUser(int $userId)
 * @method static Builder<static> withStatus(string|list<string> $status)
 * @method static Builder<static> withCategory(?string $category)
 */
final class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $table = 'support_tickets';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'user_id',
        'related_order_id',
        'related_machine_id',
        'subject',
        'category',
        'priority',
        'status',
        'assigned_to_user_id',
        'resolution_summary',
        'opened_at',
        'first_response_at',
        'closed_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancellation_reason',
        'last_message_at',
        'last_customer_view_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'category' => SupportTicketCategory::class,
            'priority' => SupportTicketPriority::class,
            'opened_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_customer_view_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (SupportTicket $ticket): void {
            if (empty($ticket->public_id)) {
                $ticket->public_id = (string) Str::uuid();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'priority',
                'assigned_to_user_id',
                'cancelled_by_user_id',
                'cancellation_reason',
                'resolution_summary',
                'first_response_at',
                'closed_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('support_ticket');
    }

    /**
     * Number of admin (non-internal) messages newer than the customer's
     * last detail-view timestamp. When the customer has never viewed the
     * ticket, every non-internal admin message counts as unread.
     *
     * Phase 12 shipped this as always-zero. Phase 19 wires the real value
     * via the `last_customer_view_at` column.
     */
    public function getUnreadMessageCountAttribute(): int
    {
        // Prefer the eager-loaded subquery alias when available — keeps
        // the customer list endpoint at a single round-trip.
        $raw = $this->getAttributes()['unread_message_count'] ?? null;
        if (is_numeric($raw)) {
            return (int) $raw;
        }

        $cutoff = $this->last_customer_view_at;
        $query = SupportTicketMessage::query()
            ->where('support_ticket_id', $this->id)
            ->where('sender_type', '=', 'admin')
            ->where('is_internal_note', false);

        if ($cutoff !== null) {
            $query->where('created_at', '>', $cutoff);
        }

        return $query->count();
    }

    /**
     * True when the customer is allowed to cancel this ticket from its
     * current state. Drives the UI `is_cancellable` flag.
     */
    public function getIsCancellableAttribute(): bool
    {
        return $this->status->isCustomerCancellable();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Order is `->withTrashed()` so a soft-deleted order still surfaces
     * in the ticket detail history.
     *
     * @return BelongsTo<Order, $this>
     */
    public function relatedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'related_order_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function relatedMachine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'related_machine_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * Full message thread (including internal notes). Customer-side
     * queries MUST filter by `is_internal_note = false`; the
     * SupportTicketService applies that filter at the service layer.
     *
     * @return HasMany<SupportTicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * All attachments across all messages on this ticket.
     *
     * @return HasMany<SupportTicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    /**
     * Append-only timeline ordered chronologically.
     *
     * @return HasMany<SupportTicketStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupportTicketStatusHistory::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<self>  $query
     * @param  string|list<string>|null  $status
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, string|array|null $status): Builder
    {
        if ($status === null || $status === '' || $status === []) {
            return $query;
        }

        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }

        return $query->where('status', $status);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithCategory(Builder $query, ?string $category): Builder
    {
        if ($category === null || $category === '') {
            return $query;
        }

        return $query->where('category', $category);
    }

    protected static function newFactory(): SupportTicketFactory
    {
        return SupportTicketFactory::new();
    }
}
