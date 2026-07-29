<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MachineSource;
use App\Enums\ProductTerm;
use App\Services\Maintenance\MaintenanceScheduler;
use Database\Factories\MachineFactory;
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
 * Physical filter unit installed at a customer's address.
 *
 * Created either by `MachinesFromExcelSeeder` from production records
 * (`source = imported`, real serial number) or by the order→install
 * listener (`source = created`, `PENDING-...` sentinel serial). Phase
 * 18 admin endpoints can later overwrite the serial.
 *
 * `next_maintenance_due_at` is recomputed by
 * {@see MaintenanceScheduler} every time a
 * `completed` log row is added.
 *
 * See docs/architecture/machines-maintenance.md § 8.1.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $address_id
 * @property int $product_id
 * @property string $serial_number
 * @property ProductTerm $term
 * @property Carbon $installed_at
 * @property int|null $filter_change_interval_months
 * @property Carbon|null $next_maintenance_due_at
 * @property string|null $location_label
 * @property string $country_code
 * @property string|null $notes
 * @property bool $is_active
 * @property MachineSource $source
 * @property int|null $order_id
 * @property int|null $order_installation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Address|null $address
 * @property-read Product|null $product
 * @property-read Order|null $order
 *
 * @method static Builder<static> forUser(int $userId)
 * @method static Builder<static> active()
 * @method static Builder<static> dueWithinDays(int $days)
 */
final class Machine extends Model
{
    /** @use HasFactory<MachineFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $table = 'machines';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'user_id',
        'address_id',
        'product_id',
        'serial_number',
        'term',
        'installed_at',
        'filter_change_interval_months',
        'next_maintenance_due_at',
        'location_label',
        'country_code',
        'notes',
        'voltage',
        'is_active',
        'individual_buy_enabled',
        'source',
        'order_id',
        'order_installation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term' => ProductTerm::class,
            'source' => MachineSource::class,
            'installed_at' => 'date',
            'next_maintenance_due_at' => 'date',
            'is_active' => 'boolean',
            'individual_buy_enabled' => 'boolean',
            'filter_change_interval_months' => 'integer',
        ];
    }

    /**
     * Auto-fill `public_id` with a UUID on creation, mirroring the
     * `Order::public_id` idiom. Tests may pre-set it via the factory.
     */
    protected static function booted(): void
    {
        self::creating(function (Machine $machine): void {
            if (empty($machine->public_id)) {
                $machine->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * Short, distinctive identifier for UI lists.
     *
     * Real imported serials are returned as-is. For machines created
     * from orders (sentinel PENDING-… or TEST-… serials) a formatted
     * code is built from the product code, installation year, and a
     * zero-padded machine id — matching the production serial style:
     *   {product_code}-{year}-{####}
     */
    public function displayName(): string
    {
        $serial = $this->serial_number;

        // Real imported serial — keep it unchanged.
        if ($serial !== '' && $serial !== null
            && ! str_starts_with($serial, 'PENDING-')
            && ! str_starts_with($serial, 'TEST-')
        ) {
            return $serial;
        }

        $code = $this->relationLoaded('product') && $this->product !== null
            ? ($this->product->code ?? 'MCH')
            : 'MCH';
        $year = $this->installed_at instanceof Carbon
            ? $this->installed_at->year
            : now()->year;
        $seq = str_pad((string) ($this->id ?? 0), 4, '0', STR_PAD_LEFT);

        return sprintf('%s-%d-%s', $code, $year, $seq);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'user_id',
                'address_id',
                'product_id',
                'serial_number',
                'term',
                'installed_at',
                'filter_change_interval_months',
                'next_maintenance_due_at',
                'location_label',
                'country_code',
                'notes',
                'is_active',
                'source',
                'order_id',
                'order_installation_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('machine');
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
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return BelongsTo<OrderInstallation, $this>
     */
    public function orderInstallation(): BelongsTo
    {
        return $this->belongsTo(OrderInstallation::class);
    }

    /**
     * @return HasMany<MachineMaintenanceLog, $this>
     */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MachineMaintenanceLog::class)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');
    }

    /**
     * @return HasMany<MaintenanceRequest, $this>
     */
    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class)->orderByDesc('id');
    }

    /**
     * Scope to a single owner.
     *
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
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Machines whose `next_maintenance_due_at` falls within $days of NOW
     * (overdue rows included — `<= NOW() + $days`), excluding inactive
     * and soft-deleted rows.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueWithinDays(Builder $query, int $days): Builder
    {
        return $query
            ->whereNotNull('next_maintenance_due_at')
            ->where('next_maintenance_due_at', '<=', now()->addDays($days)->toDateString());
    }

    protected static function newFactory(): MachineFactory
    {
        return MachineFactory::new();
    }
}
