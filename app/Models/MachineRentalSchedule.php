<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MachineRentalScheduleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Recurring payment schedule for a rented machine.
 *
 * @property int $id
 * @property int $machine_id
 * @property int $user_id
 * @property int|null $payment_card_id
 * @property string $amount
 * @property string $currency
 * @property string $frequency
 * @property Carbon $next_payment_due_at
 * @property Carbon|null $last_payment_at
 * @property Carbon|null $reminder_sent_at
 * @property MachineRentalScheduleStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Machine|null $machine
 * @property-read User|null $user
 * @property-read PaymentCard|null $paymentCard
 * @property-read list<MachineRentalPayment> $payments
 *
 * @method static Builder<static> active()
 * @method static Builder<static> dueOnDate(string $date)
 * @method static Builder<static> dueInDays(int $days)
 */
final class MachineRentalSchedule extends Model
{
    protected $table = 'machine_rental_schedules';

    /** @var list<string> */
    protected $fillable = [
        'machine_id',
        'user_id',
        'payment_card_id',
        'amount',
        'currency',
        'frequency',
        'next_payment_due_at',
        'last_payment_at',
        'reminder_sent_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MachineRentalScheduleStatus::class,
            'amount' => 'decimal:2',
            'next_payment_due_at' => 'date',
            'last_payment_at' => 'date',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PaymentCard, $this>
     */
    public function paymentCard(): BelongsTo
    {
        return $this->belongsTo(PaymentCard::class);
    }

    /**
     * @return HasMany<MachineRentalPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(MachineRentalPayment::class)
            ->orderByDesc('due_date')
            ->orderByDesc('id');
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MachineRentalScheduleStatus::Active->value);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeDueOnDate(Builder $query, string $date): Builder
    {
        return $query->active()->whereDate('next_payment_due_at', $date);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeDueInDays(Builder $query, int $days): Builder
    {
        return $query->active()->whereDate('next_payment_due_at', now()->addDays($days)->toDateString());
    }
}
