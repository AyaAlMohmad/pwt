<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FileType;
use App\Jobs\PurgeFileJob;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A stored file owned by a user.
 *
 * Lifecycle: `active` → soft-deleted (`deleted_at` set, controller path)
 * → physically purged (queued {@see PurgeFileJob}).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $filename_original
 * @property string $mime
 * @property int $size
 * @property FileType $type
 * @property string|null $checksum_sha256
 * @property bool $is_public
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected $table = 'files';

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'user_id',
        'disk',
        'path',
        'filename_original',
        'mime',
        'size',
        'type',
        'checksum_sha256',
        'is_public',
    ];

    /**
     * Internal fields never serialised through the API resource.
     *
     * @var list<string>
     */
    protected $hidden = [
        'path',
        'checksum_sha256',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FileType::class,
            'size' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Spatie activity log configuration: log create + delete only.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['uuid', 'user_id', 'type', 'mime', 'size', 'disk', 'is_public'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('files');
    }

    /**
     * Owner of the file.
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
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Restrict to one or more types.
     *
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeOfType(Builder $query, FileType ...$types): Builder
    {
        $values = array_map(static fn (FileType $t): string => $t->value, $types);

        return $query->whereIn('type', $values);
    }

    /**
     * Anonymous (unsigned) URL for files stored on a public disk.
     *
     * Returns `null` for private files — callers should use
     * {@see signedDownloadUrl()} instead.
     */
    public function publicUrl(): ?string
    {
        if (! $this->is_public) {
            return null;
        }

        /** @var string $url */
        $url = Storage::disk($this->disk)->url($this->path);

        return $url;
    }

    /**
     * Short-lived signed URL pointing at the controlled download endpoint.
     *
     * Used uniformly for BOTH public and private files so the client URL
     * pattern is consistent (per orchestrator decision on architecture
     * § 9.4). 10-minute TTL.
     */
    public function signedDownloadUrl(): string
    {
        return URL::temporarySignedRoute(
            'files.download',
            now()->addMinutes(10),
            ['uuid' => $this->uuid],
        );
    }

    protected static function newFactory(): FileFactory
    {
        return FileFactory::new();
    }
}
