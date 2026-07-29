<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallationStatus;
use Database\Factories\OrderInstallationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Scheduled installation appointment for an {@see Order}.
 *
 * MVP enforces one installation per order via the unique index on
 * `order_id`. The `address_snapshot` JSON freezes the delivery
 * address at scheduling time.
 *
 * @property int $id
 * @property int $order_id
 * @property Carbon $scheduled_for_at
 * @property int|null $technician_user_id
 * @property string|null $installation_notes
 * @property array<string, mixed>|null $address_snapshot
 * @property InstallationStatus $status
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order|null $order
 * @property-read User|null $technician
 */
final class OrderInstallation extends Model
{
    /** @use HasFactory<OrderInstallationFactory> */
    use HasFactory;

    protected $table = 'order_installations';

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'scheduled_for_at',
        'technician_user_id',
        'installation_notes',
        'address_snapshot',
        'status',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for_at' => 'datetime',
            'completed_at' => 'datetime',
            'address_snapshot' => 'array',
            'status' => InstallationStatus::class,
        ];
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
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_user_id');
    }

    protected static function newFactory(): OrderInstallationFactory
    {
        return OrderInstallationFactory::new();
    }
}
