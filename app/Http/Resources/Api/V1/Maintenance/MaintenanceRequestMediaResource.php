<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Trimmed copy of the {@see File} shape, scoped to the maintenance-
 * request context.
 *
 * Always emits a signed download URL (the maintenance_media file type
 * lives on the private disk per Phase 4).
 *
 * @property-read File $resource
 */
final class MaintenanceRequestMediaResource extends JsonResource
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
            'size' => (int) $file->size,
            'created_at' => $file->created_at->toIso8601String(),
        ];
    }
}
