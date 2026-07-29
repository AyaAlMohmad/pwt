<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PromotionRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable audit row recording an applied promotion.
 *
 * Per docs/architecture/promotions.md § 8.5, **no Phase 7 code writes to
 * this table** — Phase 8's `OrderService` populates it inside the
 * order-creation transaction. The model exists in Phase 7 because:
 *
 *  - `PromotionEngine::evaluate()` reads from it to compute the
 *    `over_total_limit` / `over_user_limit` checks.
 *  - The `Promotion::redemptions()` relation is wired now so admin
 *    reporting in Phase 20 has no follow-up work.
 *
 * `order_id` is nullable in Phase 7; Phase 8 fills it in. The
 * `order()` relation is shipped now (resolves to null until orders
 * exist) so the surface is stable.
 *
 * @property int $id
 * @property int $promotion_id
 * @property int $user_id
 * @property int|null $order_id
 * @property string $amount_applied
 * @property string $currency
 * @property Carbon $applied_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class PromotionRedemption extends Model
{
    /** @use HasFactory<PromotionRedemptionFactory> */
    use HasFactory;

    protected $table = 'promotion_redemptions';

    /** @var list<string> */
    protected $fillable = [
        'promotion_id',
        'user_id',
        'order_id',
        'amount_applied',
        'currency',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_applied' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Phase 8: the order whose confirmation triggered this redemption.
     * Nullable until Phase 17 backfills any historical rows.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function newFactory(): PromotionRedemptionFactory
    {
        return PromotionRedemptionFactory::new();
    }
}
