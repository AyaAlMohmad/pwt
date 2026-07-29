<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use Database\Factories\MaintenanceRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Customer-submitted maintenance request for a physical {@see Machine}.
 *
 * State machine: new → assigned → in_progress → completed (or cancelled
 * from any non-terminal state). See
 * docs/architecture/machines-maintenance.md § 2.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $machine_id
 * @property MaintenanceIssueType $issue_type
 * @property string $description
 * @property string|null $internal_notes
 * @property MaintenanceStatus $status
 * @property MaintenancePriority $priority
 * @property Carbon|null $preferred_date
 * @property string|null $preferred_time
 * @property int|null $assigned_to_user_id
 * @property string|null $resolution_notes
 * @property Carbon|null $closed_at
 * @property string|null $cancellation_reason
 * @property int|null $cancelled_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Machine|null $machine
 * @property-read User|null $assignedTo
 * @property-read User|null $cancelledBy
 *
 * @method static Builder<static> forUser(int $userId)
 * @method static Builder<static> withStatus(string|list<string> $status)
 */
final class MaintenanceRequest extends Model
{
    /** @use HasFactory<MaintenanceRequestFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $table = 'maintenance_requests';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'user_id',
        'machine_id',
        'issue_type',
        'description',
        'internal_notes',
        'status',
        'priority',
        'preferred_date',
        'preferred_time',
        'assigned_to_user_id',
        'resolution_notes',
        'closed_at',
        'cancellation_reason',
        'cancelled_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MaintenanceStatus::class,
            'priority' => MaintenancePriority::class,
            'issue_type' => MaintenanceIssueType::class,
            'preferred_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (MaintenanceRequest $request): void {
            if (empty($request->public_id)) {
                $request->public_id = (string) Str::uuid();
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'priority',
                'assigned_to_user_id',
                'resolution_notes',
                'cancellation_reason',
                'cancelled_by_user_id',
                'closed_at',
                'internal_notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('maintenance_request');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * Files attached to this request via the `maintenance_request_media`
     * pivot.
     *
     * @return BelongsToMany<File, $this>
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(
            File::class,
            'maintenance_request_media',
            'maintenance_request_id',
            'file_id',
        )->withPivot('created_at');
    }

    /**
     * Append-only timeline ordered chronologically.
     *
     * @return HasMany<MaintenanceStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(MaintenanceStatusHistory::class)
            ->orderBy('created_at')
            ->orderBy('id');
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
     * Short, distinctive identifier for UI lists — e.g. MAINT-114.
     */
    public function displayName(): string
    {
        return 'MAINT-' . str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    protected static function newFactory(): MaintenanceRequestFactory
    {
        return MaintenanceRequestFactory::new();
    }
}
