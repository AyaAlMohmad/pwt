<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MachineRentalPaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payment attempt against a machine rental schedule.
 *
 * @property int $id
 * @property int $machine_rental_schedule_id
 * @property int $machine_id
 * @property int $user_id
 * @property int|null $payment_card_id
 * @property string $amount
 * @property string $currency
 * @property MachineRentalPaymentStatus $status
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 * @property string|null $stripe_payment_intent_id
 * @property string|null $failure_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read MachineRentalSchedule|null $schedule
 * @property-read Machine|null $machine
 * @property-read User|null $user
 * @property-read PaymentCard|null $paymentCard
 *
 * @method static Builder<static> pending()
 * @method static Builder<static> dueToday()
 */
final class MachineRentalPayment extends Model
{
    protected $table = 'machine_rental_payments';

    /** @var list<string> */
    protected $fillable = [
        'machine_rental_schedule_id',
        'machine_id',
        'user_id',
        'payment_card_id',
        'amount',
        'currency',
        'status',
        'due_date',
        'paid_at',
        'stripe_payment_intent_id',
        'failure_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MachineRentalPaymentStatus::class,
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MachineRentalSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MachineRentalSchedule::class, 'machine_rental_schedule_id');
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
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', MachineRentalPaymentStatus::Pending->value);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->pending()->whereDate('due_date', now()->toDateString());
    }
}
