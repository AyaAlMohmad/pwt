<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Application user.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $stripe_customer_id
 * @property string $phone_country_code
 * @property string|null $phone
 * @property string $locale
 * @property bool $is_active
 * @property string $password
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /**
     * Mass-assignable attributes.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone_country_code',
        'phone',
        'locale',
        'is_active',
        'password',
        'last_login_at',
        'avatar_file_id',
        'account_type',
        'company_name',
        'stripe_customer_id',
    ];

    /**
     * Attributes hidden from serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'account_type' => AccountType::class,
        ];
    }

    /**
     * Active = is_active=true AND not soft-deleted.
     *
     * The query scope is the canonical "is this user allowed to receive
     * a token?" filter used by the auth services. Soft-deletion is
     * already excluded by the global `SoftDeletes` scope; the extra
     * check here keeps the call sites readable.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Address book entries for this user.
     *
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'user_id')->orderByDesc('is_default');
    }

    /**
     * Orders placed by this user.
     *
     * Backed by the FK `orders.user_id`. Used by admin counters
     * (`withCount('orders')`) — soft-deleted orders are excluded by the
     * SoftDeletes global scope on {@see Order}.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    /**
     * Support tickets opened by this user.
     *
     * Backed by `support_tickets.user_id`. Soft-deleted tickets are
     * excluded by the SoftDeletes global scope on {@see SupportTicket}.
     *
     * @return HasMany<SupportTicket, $this>
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'user_id');
    }

    /**
     * Machines owned by this user.
     *
     * Backed by `machines.user_id`. Soft-deleted machines are excluded
     * by the SoftDeletes global scope on {@see Machine}.
     *
     * @return HasMany<Machine, $this>
     */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class, 'user_id');
    }

    /**
     * Avatar file for this user.
     *
     * @return BelongsTo<File, $this>
     */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(File::class, 'avatar_file_id');
    }

    /**
     * Payment cards saved by this user.
     *
     * @return HasMany<PaymentCard, $this>
     */
    public function paymentCards(): HasMany
    {
        return $this->hasMany(PaymentCard::class, 'user_id');
    }
}
