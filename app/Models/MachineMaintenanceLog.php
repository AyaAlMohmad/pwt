<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceLogSource;
use App\Enums\MaintenanceLogStatus;
use Database\Factories\MachineMaintenanceLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-machine maintenance event row — scheduled, completed, missed, or
 * cancelled.
 *
 * The Excel seeder writes ~1800+ of these for the 227 real machines
 * (one row per maintenance date column J..AB of the source workbook),
 * all with `status = completed` and `source = imported`.
 *
 * @property int $id
 * @property int $machine_id
 * @property MaintenanceLogStatus $status
 * @property Carbon $scheduled_at
 * @property Carbon|null $completed_at
 * @property int|null $technician_user_id
 * @property string|null $notes
 * @property int|null $created_by_user_id
 * @property MaintenanceLogSource $source
 * @property int|null $maintenance_request_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Machine|null $machine
 * @property-read User|null $technician
 * @property-read User|null $createdBy
 * @property-read MaintenanceRequest|null $maintenanceRequest
 */
final class MachineMaintenanceLog extends Model
{
    /** @use HasFactory<MachineMaintenanceLogFactory> */
    use HasFactory;

    protected $table = 'machine_maintenance_logs';

    /** @var list<string> */
    protected $fillable = [
        'machine_id',
        'status',
        'scheduled_at',
        'completed_at',
        'technician_user_id',
        'notes',
        'created_by_user_id',
        'source',
        'maintenance_request_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MaintenanceLogStatus::class,
            'source' => MaintenanceLogSource::class,
            'scheduled_at' => 'date',
            'completed_at' => 'date',
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
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<MaintenanceRequest, $this>
     */
    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    protected static function newFactory(): MachineMaintenanceLogFactory
    {
        return MachineMaintenanceLogFactory::new();
    }
}
