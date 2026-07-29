<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderActorType;
use App\Services\Orders\OrderStatusTransitionService;
use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Append-only timeline of every status transition for an {@see Order}.
 *
 * Rows are written by
 * {@see OrderStatusTransitionService} inside the
 * same DB transaction as the corresponding `orders.status` mutation.
 * The model is intentionally immutable — the `updating` event is
 * blocked at the model level so a stray `->save()` against a loaded
 * row raises immediately.
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property OrderActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $note
 * @property bool $is_internal
 * @property Carbon $created_at
 * @property-read Order|null $order
 * @property-read User|null $actor
 */
final class OrderStatusHistory extends Model
{
    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    protected $table = 'order_status_history';

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'note',
        'is_internal',
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
            'actor_type' => OrderActorType::class,
            'is_internal' => 'boolean',
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
            throw new RuntimeException('OrderStatusHistory rows are immutable; updates are not allowed.');
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function newFactory(): OrderStatusHistoryFactory
    {
        return OrderStatusHistoryFactory::new();
    }
}
