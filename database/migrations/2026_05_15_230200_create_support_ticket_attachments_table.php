<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Support ticket attachments.
 *
 * Pivot-ish table linking Phase 4 `files` to a support_tickets row and,
 * optionally, to the specific `support_ticket_messages` row that
 * introduced it. Phase 12 always fills `support_ticket_message_id`;
 * null is reserved for future "ticket-level" attachments (admin
 * resolution PDFs etc).
 *
 * UNIQUE(file_id) enforces the "one-shot attachment" invariant at the
 * DB level — the same file row cannot be referenced by two
 * support_ticket_attachments rows OR by a maintenance_request_media
 * row (additional cross-domain check lives in the OwnedFile rule).
 *
 * `restrictOnDelete` on `file_id` so the Phase 4 purge job cannot run
 * while an attachment row exists. Soft-delete on the file is fine —
 * the resource layer filters at render time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('support_ticket_id')
                ->constrained('support_tickets')
                ->cascadeOnDelete();
            $table->foreignId('support_ticket_message_id')
                ->nullable()
                ->constrained('support_ticket_messages')
                ->cascadeOnDelete();
            $table->foreignId('file_id')
                ->constrained('files')
                ->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Immutable — no `updated_at`.
            $table->timestamp('created_at')->useCurrent();

            $table->unique('file_id', 'sta_file_unique');
            $table->index('support_ticket_id', 'sta_ticket_idx');
            $table->index('support_ticket_message_id', 'sta_message_idx');
            $table->index('uploaded_by_user_id', 'sta_uploader_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
