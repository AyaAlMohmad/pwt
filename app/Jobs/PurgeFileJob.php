<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Physically purge a file from disk after the DB row has been
 * soft-deleted. Idempotent — re-runs after the file is gone log a
 * warning and exit cleanly so retries don't poison the queue.
 *
 * Payload is the trio (id, disk, path) rather than a hydrated File
 * model so the job survives serialisation when the model has been
 * deleted between dispatch and handle.
 */
final class PurgeFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $fileId,
        public readonly string $disk,
        public readonly string $path,
    ) {}

    public function handle(): void
    {
        try {
            $storage = Storage::disk($this->disk);
            if (! $storage->exists($this->path)) {
                Log::warning('purge_file.missing', [
                    'file_id' => $this->fileId,
                    'disk' => $this->disk,
                ]);

                return;
            }

            $storage->delete($this->path);
        } catch (\Throwable $e) {
            // Don't leak the path into logs (it carries the UUID, but no PII).
            Log::warning('purge_file.failed', [
                'file_id' => $this->fileId,
                'disk' => $this->disk,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
