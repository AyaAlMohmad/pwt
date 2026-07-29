<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved payment card for a customer.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $brand
 * @property string $last_four
 * @property string|null $card_number_hash
 * @property string $expiry_month
 * @property string $expiry_year
 * @property string|null $cardholder_name
 * @property bool $is_default
 */
final class PaymentCard extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentCardFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'payment_cards';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'brand',
        'last_four',
        'card_number_hash',
        'cvc',
        'expiry_month',
        'expiry_year',
        'cardholder_name',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Owner of the card.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Restrict to a single owner.
     *
     * @param  Builder<PaymentCard>  $query
     * @return Builder<PaymentCard>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
