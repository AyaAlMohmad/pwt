<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot for the `maintenance_request_media` table.
 *
 * Belongs-to-many pivots in Laravel are typically managed implicitly,
 * but exposing a dedicated model lets the Phase 4 `FileService::delete`
 * detach rows via Eloquent (instead of raw DB statements) and lets
 * tests assert pivot counts directly.
 *
 * Rows are immutable — there is no `updated_at` column on the table.
 *
 * @property int $id
 * @property int $maintenance_request_id
 * @property int $file_id
 * @property Carbon $created_at
 * @property-read MaintenanceRequest|null $request
 * @property-read File|null $file
 */
final class MaintenanceRequestMedia extends Model
{
    protected $table = 'maintenance_request_media';

    /** @var list<string> */
    protected $fillable = [
        'maintenance_request_id',
        'file_id',
    ];

    /**
     * Append-only — pivots have no `updated_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MaintenanceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
