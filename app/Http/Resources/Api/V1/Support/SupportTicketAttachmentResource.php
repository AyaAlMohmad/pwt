<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Support;

use App\Models\SupportTicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing shape of a single
 * {@see SupportTicketAttachment}.
 *
 * Always emits a 10-minute signed download URL via the Phase 4
 * `files.download` route. A soft-deleted file row defensively returns
 * `download_url=null` so a customer whose file was nuked by admin
 * does not get a 404 surfaced as a JSON-shaped resource.
 *
 * @property-read SupportTicketAttachment $resource
 */
final class SupportTicketAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicketAttachment $att */
        $att = $this->resource;

        $file = $att->relationLoaded('file') ? $att->file : null;

        $downloadUrl = null;
        $mime = null;
        $size = null;
        $filename = null;
        $fileUuid = null;
        if ($file !== null && $file->deleted_at === null) {
            $downloadUrl = $file->signedDownloadUrl();
            $mime = $file->mime;
            $size = (int) $file->size;
            $filename = $file->filename_original;
            $fileUuid = $file->uuid;
        }

        return [
            'id' => $att->id,
            'file_id' => $att->file_id,
            'file_uuid' => $fileUuid,
            'filename' => $filename,
            'mime' => $mime,
            'size' => $size,
            'download_url' => $downloadUrl,
        ];
    }
}
