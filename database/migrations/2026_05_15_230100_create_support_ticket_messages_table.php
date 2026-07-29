<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Support ticket messages.
 *
 * Append-only thread under a single support_tickets row. The first
 * message is written by the customer at ticket creation; later messages
 * are admin replies (Phase 19) or customer replies (Phase 13). The
 * `is_internal_note` flag carries admin-only notes that MUST NEVER
 * surface to the customer — defence-in-depth at query, resource, and
 * service layers (docs/architecture/support.md § 6.2).
 *
 * `cascadeOnDelete` on `support_ticket_id` so a hard-delete on the
 * parent ticket purges messages. Soft-delete on the ticket leaves these
 * untouched (they remain queryable for audit recovery).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('support_ticket_id')
                ->constrained('support_tickets')
                ->cascadeOnDelete();

            $table->string('sender_type', 16);
            $table->foreignId('sender_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('body');
            $table->boolean('is_internal_note')->default(false);

            $table->timestamps();

            $table->index(
                ['support_ticket_id', 'created_at'],
                'stm_ticket_created_idx',
            );
            $table->index(
                ['support_ticket_id', 'is_internal_note', 'created_at'],
                'stm_ticket_internal_created_idx',
            );
            $table->index('sender_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
