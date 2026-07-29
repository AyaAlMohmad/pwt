<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * One row per (recipient × channel) notification hand-off.
 *
 * `in_app` rows surface in the customer inbox (`GET /notifications`);
 * other channels carry the audit trail of email / SMS / WhatsApp / push
 * sends. Title + body are translatable JSON. `data` is a safe scalar
 * context blob (e.g. `{ "order_id": 1057 }`) — never a serialised
 * Eloquent model.
 *
 * NOT soft-deleted (per CLAUDE.md § 6 — soft-deletes list).
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $type
 * @property array<string, string>|string $title
 * @property array<string, string>|string|null $body
 * @property array<string, mixed>|null $data
 * @property string|null $action_url
 * @property NotificationChannel $channel
 * @property DeliveryStatus $delivery_status
 * @property string|null $failure_reason
 * @property Carbon|null $read_at
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 *
 * @method static Builder<static> forUser(int $userId)
 * @method static Builder<static> unread()
 * @method static Builder<static> byType(string $type)
 */
final class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasTranslations;

    protected $table = 'notifications';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'action_url',
        'channel',
        'delivery_status',
        'failure_reason',
        'read_at',
        'sent_at',
    ];

    /**
     * Translatable JSON columns — spatie/laravel-translatable resolves
     * these to the active locale on access.
     *
     * @var list<string>
     */
    public array $translatable = ['title', 'body'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'channel' => NotificationChannel::class,
            'delivery_status' => DeliveryStatus::class,
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @return Builder<self>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
