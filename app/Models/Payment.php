<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Policies\PaymentPolicy;
use App\Services\Orders\OrderStatusTransitionService;
use Database\Factories\PaymentFactory;
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
 * Payment record for an {@see Order}.
 *
 * Online flows: a row is created with `status=initiated` and the
 * gateway returns a hosted-checkout URL stored in `client_payment_url`.
 * The webhook later transitions the row to `captured` / `failed`, and
 * on `captured` the linked Order is auto-confirmed via
 * {@see OrderStatusTransitionService}.
 *
 * COD flows: the row is created directly in `status=captured` and the
 * Order is confirmed in the same DB transaction.
 *
 * `user_id` is denormalised from `order.user_id` for cheap ownership
 * lookups in {@see PaymentPolicy}.
 *
 * Full state machine in docs/architecture/payments.md § 2.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $user_id
 * @property PaymentMethod $payment_method
 * @property string $gateway
 * @property string|null $gateway_transaction_id
 * @property string|null $gateway_reference
 * @property string $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $failure_reason
 * @property string|null $client_payment_url
 * @property string|null $idempotency_key
 * @property Carbon|null $authorized_at
 * @property Carbon|null $captured_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Order|null $order
 * @property-read User|null $user
 *
 * @method static Builder<static> forUser(int $userId)
 * @method static Builder<static> pending()
 * @method static Builder<static> terminal()
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $table = 'payments';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'order_id',
        'user_id',
        'payment_method',
        'gateway',
        'gateway_transaction_id',
        'gateway_reference',
        'amount',
        'currency',
        'status',
        'failure_reason',
        'client_payment_url',
        'idempotency_key',
        'authorized_at',
        'captured_at',
        'failed_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Spatie activity log: capture status transitions + the timestamps
     * that drive them, plus the failure reason for forensic admin views.
     * `gateway_transaction_id` is NEVER logged in plain text (it lives in
     * `payment_events.payload` for admin-only inspection).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'failure_reason',
                'authorized_at',
                'captured_at',
                'failed_at',
                'cancelled_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payment');
    }

    protected static function booted(): void
    {
        self::creating(function (self $payment): void {
            $current = $payment->getAttribute('public_id');
            if ($current === null || $current === '') {
                $payment->public_id = (string) Str::uuid();
            }
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PaymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    /**
     * Restrict to payments owned by a user (denormalised column).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Active "in-flight" payments: status=initiated AND created within
     * the configured TTL window. The TTL is config-driven so the scope
     * can be tightened/loosened without a migration.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        $ttl = (int) config('payment.initiated_ttl_minutes', 30);

        return $query
            ->where('status', PaymentStatus::Initiated->value)
            ->where('created_at', '>=', Carbon::now()->subMinutes($ttl));
    }

    /**
     * Terminal payments: captured / failed / cancelled / refunded.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Captured->value,
            PaymentStatus::Failed->value,
            PaymentStatus::Cancelled->value,
            PaymentStatus::Refunded->value,
        ]);
    }

    /**
     * True iff status=initiated AND older than the configured TTL.
     * Used by the re-initiate guard — an expired `initiated` row does
     * NOT block a new attempt.
     */
    public function isExpired(): bool
    {
        if ($this->status !== PaymentStatus::Initiated) {
            return false;
        }

        $ttl = (int) config('payment.initiated_ttl_minutes', 30);

        return $this->created_at->lt(Carbon::now()->subMinutes($ttl));
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
