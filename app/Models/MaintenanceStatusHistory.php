<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceActorType;
use App\Services\Maintenance\MaintenanceRequestService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Append-only timeline of every status transition for a
 * {@see MaintenanceRequest}.
 *
 * Rows are written by {@see MaintenanceRequestService} inside the same
 * DB transaction as the corresponding `maintenance_requests.status`
 * mutation. The `updating` model event is blocked so a stray `->save()`
 * on a loaded row raises immediately.
 *
 * @property int $id
 * @property int $maintenance_request_id
 * @property string|null $from_status
 * @property string $to_status
 * @property MaintenanceActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read MaintenanceRequest|null $request
 * @property-read User|null $actor
 */
final class MaintenanceStatusHistory extends Model
{
    protected $table = 'maintenance_status_history';

    /** @var list<string> */
    protected $fillable = [
        'maintenance_request_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'note',
    ];

    /**
     * Append-only — no `updated_at` column.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => MaintenanceActorType::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Block any update path. Status-history rows are immutable; the
     * service writes them once and never mutates them.
     */
    protected static function booted(): void
    {
        self::updating(function (): bool {
            throw new RuntimeException(
                'MaintenanceStatusHistory rows are immutable; updates are not allowed.',
            );
        });
    }

    /**
     * @return BelongsTo<MaintenanceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
