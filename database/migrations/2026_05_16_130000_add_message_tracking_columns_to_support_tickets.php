<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 — Admin Support Tickets: denormalised message-tracking columns.
 *
 *   - `last_message_at`        — denormalised MAX(support_ticket_messages.created_at).
 *                                Drives the admin list "recently-touched" sort as a
 *                                covered index scan, and the customer list inbox.
 *   - `last_customer_view_at`  — stamped by the customer-side `GET /api/v1/support-
 *                                tickets/{id}` so the unread_message_count accessor
 *                                can count admin (non-internal) messages newer than
 *                                this timestamp.
 *
 * After adding the columns, this migration backfills `last_message_at` from
 * `MAX(support_ticket_messages.created_at)` so the admin sort works on day 1.
 *
 * See docs/architecture/admin-support.md § 8.1 / § 8.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->timestamp('last_message_at')->nullable()->after('cancellation_reason');
            $table->timestamp('last_customer_view_at')->nullable()->after('last_message_at');

            $table->index('last_message_at', 'st_last_message_idx');
            $table->index(
                ['status', 'last_message_at'],
                'st_status_last_message_idx',
            );
        });

        // Backfill — pre-Phase-19 tickets get their persisted
        // last_message_at populated from the message thread. Uses
        // unaliased SQL so MySQL + SQLite both accept the UPDATE …
        // FROM (subquery) form (SQLite < 3.33 rejects table aliases on
        // UPDATE; our test bootstrap uses in-memory SQLite).
        DB::statement(<<<'SQL'
            UPDATE support_tickets
            SET last_message_at = (
                SELECT MAX(support_ticket_messages.created_at)
                FROM support_ticket_messages
                WHERE support_ticket_messages.support_ticket_id = support_tickets.id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropIndex('st_status_last_message_idx');
            $table->dropIndex('st_last_message_idx');
            $table->dropColumn('last_customer_view_at');
            $table->dropColumn('last_message_at');
        });
    }
};
