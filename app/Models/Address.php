<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Addresses\AddressService;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Customer address book entry.
 *
 * Each row is owned by exactly one customer (`user_id`) and represents
 * a single delivery destination. The `is_default` flag is mutually
 * exclusive within a single `user_id` scope — the {@see
 * \App\Services\Addresses\AddressService} enforces that invariant
 * inside a `DB::transaction` with `lockForUpdate()`, since MySQL 8
 * lacks partial unique indexes.
 *
 * Phase 8 (Orders) will reference `addresses.id` as
 * `orders.delivery_address_id`; soft-deleted rows therefore stay
 * joinable for historical receipts.
 *
 * @property int $id
 * @property int $user_id
 * @property int $country_id
 * @property int|null $region_id
 * @property string $label
 * @property string $recipient_name
 * @property string $recipient_phone
 * @property string $recipient_phone_country_code
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string|null $area
 * @property string|null $postal_code
 * @property string|null $notes
 * @property bool $is_default
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Country|null $country
 * @property-read Region|null $region
 *
 * @method static Builder<static> forUser(int $userId)
 * @method static Builder<static> default()
 */
final class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $table = 'addresses';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'country_id',
        'region_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'recipient_phone_country_code',
        'line1',
        'line2',
        'city',
        'area',
        'postal_code',
        'notes',
        'is_default',
        'latitude',
        'longitude',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Spatie activity log configuration: log create / update / delete
     * with the changed-attribute diff. The log name is `address` so
     * downstream audit queries can filter by domain.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'user_id',
                'country_id',
                'region_id',
                'label',
                'recipient_name',
                'recipient_phone',
                'recipient_phone_country_code',
                'line1',
                'line2',
                'city',
                'area',
                'postal_code',
                'notes',
                'is_default',
                'latitude',
                'longitude',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('address');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Restrict the query to addresses owned by a specific user id.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Restrict the query to default addresses only.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Orders that reference this address as their delivery destination.
     * Used by the Phase 8 ERR_ADDRESS_IN_USE guard
     * ({@see AddressService::isAddressInUse}).
     *
     * @return HasMany<Order, $this>
     */
    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_address_id');
    }

    /**
     * Orders that reference this address as their billing destination.
     *
     * @return HasMany<Order, $this>
     */
    public function billingOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'billing_address_id');
    }

    protected static function newFactory(): AddressFactory
    {
        return AddressFactory::new();
    }
}
