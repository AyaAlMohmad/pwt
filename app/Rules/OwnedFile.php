<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\FileType;
use App\Models\File;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates that a file id refers to a row:
 *   1. owned by the currently-authenticated user,
 *   2. of the expected `FileType` (e.g. `maintenance_media`,
 *      `support_attachment`),
 *   3. not soft-deleted, and
 *   4. not already attached to ANOTHER domain row (one-shot attachment).
 *      The same file cannot be re-used across maintenance requests OR
 *      across support tickets.
 *
 * Surfaces a 422 `ERR_FILE_NOT_OWNED` with the per-index path so the
 * mobile client can highlight which thumbnail failed. The translation
 * key is chosen based on `expectedType` so the surfaced error string
 * matches the calling module's voice.
 */
final class OwnedFile implements ValidationRule
{
    public function __construct(
        private readonly int $userId,
        private readonly FileType $expectedType,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $fileId = (int) $value;

        $file = File::query()
            ->where('id', $fileId)
            ->where('user_id', $this->userId)
            ->where('type', $this->expectedType->value)
            ->whereNull('deleted_at')
            ->first();

        if ($file === null) {
            $fail($this->errorMessage());

            return;
        }

        // One-shot attachment: reject when the file already has a row in
        // ANY non-soft-deleted maintenance_request_media pivot.
        $alreadyOnMaintenance = DB::table('maintenance_request_media')
            ->where('file_id', $fileId)
            ->exists();

        if ($alreadyOnMaintenance) {
            $fail($this->errorMessage());

            return;
        }

        // One-shot attachment: reject when the file already has a row in
        // any support_ticket_attachments row (Phase 12 extension).
        $alreadyOnSupportTicket = DB::table('support_ticket_attachments')
            ->where('file_id', $fileId)
            ->exists();

        if ($alreadyOnSupportTicket) {
            $fail($this->errorMessage());
        }
    }

    /**
     * Surface the calling module's translation key so error messages
     * match the voice of the relevant domain. Defaults to the
     * maintenance copy for backward compatibility.
     */
    private function errorMessage(): string
    {
        return match ($this->expectedType) {
            FileType::SupportAttachment => __('support.file_not_owned'),
            default => __('maintenance.file_not_owned'),
        };
    }
}
