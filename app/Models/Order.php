<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductTerm;
use App\Services\Orders\OrderService;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\OrderMilestone;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Customer order.
 *
 * Every status transition is audited via the `order_status_history`
 * table (customer-visible) AND through Spatie activity log
 * (admin-visible). See docs/architecture/orders.md § 2.4. All monetary
 * fields are recomputed by {@see OrderService} on
 * create; the model intentionally exposes no setters for them.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $delivery_address_id
 * @property int|null $billing_address_id
 * @property int|null $promotion_id
 * @property ProductTerm $term
 * @property PaymentMethod $payment_method
 * @property OrderStatus $status
 * @property string $currency
 * @property string $subtotal_amount
 * @property string $discount_amount
 * @property string $tax_amount
 * @property string $tax_rate
 * @property string $shipping_amount
 * @property string $total_amount
 * @property string|null $idempotency_key
 * @property string|null $idempotency_request_hash
 * @property string|null $notes
 * @property string|null $cancellation_reason
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $scheduled_for_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $installed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Address|null $deliveryAddress
 * @property-read Address|null $billingAddress
 * @property-read Promotion|null $promotion
 * @property-read User|null $cancelledBy
 *
 * @method static Builder<static> forUser(int $userId)
 * @method static Builder<static> withStatus(string|list<string> $status)
 * @method static Builder<static> placedBetween(?string $from, ?string $to)
 */
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $table = 'orders';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'po_number',
        'user_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_address_id',
        'delivery_address_line_1',
        'delivery_date',
        'delivery_time_slot',
        'billing_address_id',
        'promotion_id',
        'term',
        'payment_method',
        'status',
        'currency',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'tax_rate',
        'shipping_amount',
        'total_amount',
        'idempotency_key',
        'idempotency_request_hash',
        'notes',
        'installation_preferred_time',
        'card_last_four',
        'card_expiry_month',
        'card_expiry_year',
        'card_cvc',
        'cancellation_reason',
        'confirmed_at',
        'scheduled_for_at',
        'delivered_at',
        'installed_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term' => ProductTerm::class,
            'payment_method' => PaymentMethod::class,
            'status' => OrderStatus::class,
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'card_last_four' => 'string',
            'card_expiry_month' => 'string',
            'card_expiry_year' => 'string',
            'card_cvc' => 'string',
            'delivery_date' => 'date',
            'confirmed_at' => 'datetime',
            'scheduled_for_at' => 'datetime',
            'delivered_at' => 'datetime',
            'installed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'cancellation_reason',
                'cancelled_by_user_id',
                'confirmed_at',
                'scheduled_for_at',
                'delivered_at',
                'installed_at',
                'completed_at',
                'cancelled_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('order');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    /**
     * @return HasOne<OrderInstallation, $this>
     */
    public function installation(): HasOne
    {
        return $this->hasOne(OrderInstallation::class);
    }

    /**
     * @return HasMany<OrderInstallation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(OrderInstallation::class);
    }

    /**
     * @return HasMany<OrderMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(OrderMilestone::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * @return HasOne<PromotionRedemption, $this>
     */
    public function redemption(): HasOne
    {
        return $this->hasOne(PromotionRedemption::class);
    }

    /**
     * Phase 9 — all payments attached to this order. New attempts
     * (e.g. retry after a failed card) create additional rows; this
     * relation returns them all so the admin / detail resource can
     * pick the latest. Use `latestPayment()` for the most recent.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    /**
     * Most recent non-cancelled Payment, evaluated in PHP from the
     * `payments` collection (so the caller can `with('payments')`
     * once and reuse the data). Returns null when there are no
     * payments yet.
     */
    public function latestPayment(): ?Payment
    {
        if (! $this->relationLoaded('payments')) {
            return $this->payments()
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('id')
                ->first();
        }

        /** @var Collection<int, Payment> $payments */
        $payments = $this->payments;

        $filtered = $payments->filter(static fn (Payment $p): bool => $p->status->value !== 'cancelled');

        return $filtered->first();
    }

    /**
     * Recompute monetary totals from the loaded line items + promotion.
     *
     * Pure function — does NOT persist. Returns the computed totals so
     * the caller (typically {@see OrderService}) can
     * persist them inside the order-creation transaction.
     *
     * Tax base is `(subtotal - discount)`; tax is rounded to 2 decimals.
     *
     * @return array{subtotal: string, discount: string, tax: string, total: string}
     */
    public function recomputeTotals(): array
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->get();

        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item->line_subtotal;
        }

        $discount = (float) $this->discount_amount;
        $taxBase = max(0.0, $subtotal - $discount);
        $tax = round($taxBase * ((float) $this->tax_rate / 100.0), 2);
        $shipping = (float) $this->shipping_amount;
        $total = round($subtotal - $discount + $tax + $shipping, 2);

        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'discount' => number_format($discount, 2, '.', ''),
            'tax' => number_format($tax, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ];
    }

    /**
     * True when the order can be cancelled by its customer owner. Used
     * by the resource to populate the `is_cancellable` UI flag without
     * the client re-implementing the rule.
     */
    public function getIsCancellableAttribute(): bool
    {
        return $this->status->isCustomerCancellable();
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
     * @param  string|list<string>  $status
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, string|array $status): Builder
    {
        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }

        return $query->where('status', $status);
    }

    /**
     * Filter orders to those placed within an inclusive date window.
     * Both bounds optional — pass null to leave the side open.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePlacedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from !== null) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to !== null) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return $query;
    }

    /**
     * Short, distinctive identifier for UI lists — e.g. #PWT-0841.
     */
    public function displayName(): string
    {
        return '#PWT-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
