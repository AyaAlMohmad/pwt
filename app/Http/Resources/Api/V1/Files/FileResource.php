<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Files;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a {@see File} row.
 *
 * Allowlist (per docs/architecture/files.md § 4.1):
 *   id, uuid, url, mime, size, type, is_public, created_at.
 *
 * Never serialised: path, checksum_sha256, disk, user_id.
 *
 * @property-read File $resource
 */
final class FileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var File $file */
        $file = $this->resource;

        return [
            'id' => $file->id,
            'uuid' => $file->uuid,
            'url' => $file->signedDownloadUrl(),
            'mime' => $file->mime,
            'size' => $file->size,
            'type' => $file->type->value,
            'is_public' => $file->is_public,
            'created_at' => $file->created_at->toIso8601String(),
        ];
    }
}
