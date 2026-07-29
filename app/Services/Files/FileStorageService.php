<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Enums\FileType;
use App\Jobs\PurgeFileJob;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Coordinates the on-disk + DB-row lifecycle of stored files.
 *
 * Responsibilities:
 *   1. UUID generation, mime sniffing, extension derivation.
 *   2. Disk selection (`public` vs `local`) based on the type.
 *   3. Atomic write (disk move + DB insert) inside a transaction so a
 *      partial write never leaves an orphan row.
 *   4. Soft-delete + queued physical purge on removal.
 *   5. Authorised streaming download for both public and private files
 *      (uniform endpoint per orchestrator decision).
 */
final class FileStorageService
{
    /**
     * Persist an uploaded file and create the matching DB row.
     */
    public function store(UploadedFile $upload, User $user, FileType $type): File
    {
        $uuid = (string) Str::uuid();
        $disk = $type->disk();
        $mime = (string) $upload->getMimeType();
        $extension = $this->extensionForMime($mime, $upload);
        $path = "files/{$type->value}/{$uuid}.{$extension}";
        $size = (int) $upload->getSize();
        $checksum = (string) hash_file('sha256', $upload->getRealPath());
        $originalName = $this->sanitiseFilename($upload->getClientOriginalName(), $extension);

        return DB::transaction(function () use (
            $upload, $user, $type, $uuid, $disk, $path, $mime, $size, $checksum, $originalName, $extension,
        ): File {
            // Write to disk first; if this fails we never insert the row.
            $stored = $upload->storeAs(
                "files/{$type->value}",
                "{$uuid}.{$extension}",
                ['disk' => $disk],
            );

            if ($stored === false) {
                throw new \RuntimeException('Failed to write upload to disk.');
            }

            return File::query()->create([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'disk' => $disk,
                'path' => $path,
                'filename_original' => $originalName,
                'mime' => $mime,
                'size' => $size,
                'type' => $type->value,
                'checksum_sha256' => $checksum,
                'is_public' => $type->isPublic(),
            ]);
        });
    }

    /**
     * Soft-delete the DB row and dispatch the physical purge.
     *
     * The HTTP response is immediate; physical disk cleanup happens on
     * the queue worker (or, in dev with no worker running, accumulates
     * until manually flushed — acceptable per orchestrator decision).
     */
    public function softDelete(File $file): void
    {
        DB::transaction(function () use ($file): void {
            $file->delete();
        });

        PurgeFileJob::dispatch($file->id, $file->disk, $file->path);
    }

    /**
     * Stream a file to the caller as an attachment download.
     */
    public function streamDownload(File $file): StreamedResponse
    {
        /** @var StreamedResponse $response */
        $response = Storage::disk($file->disk)->download($file->path, $file->filename_original);

        return $response;
    }

    /**
     * Map a sniffed mime to a safe file extension. The original client
     * extension is NEVER trusted — we derive purely from the mime so a
     * renamed `evil.php` cannot retain its `.php` suffix on disk.
     */
    private function extensionForMime(string $mime, UploadedFile $upload): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    /**
     * Reduce the client filename to display-only ASCII + the derived
     * extension. Never used as a path component.
     */
    private function sanitiseFilename(string $original, string $extension): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $slug = Str::slug($base, '-') ?: 'file';

        return $slug.'.'.$extension;
    }
}
