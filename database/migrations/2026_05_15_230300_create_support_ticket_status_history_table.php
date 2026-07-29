<?php

declare(strict_types=1);

use App\Models\SupportTicketStatusHistory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Append-only timeline of support ticket transitions.
 *
 * Mirrors `order_status_history` (Phase 8) and `maintenance_status_history`
 * (Phase 10) exactly. Immutability is enforced at the model level via
 * {@see SupportTicketStatusHistory::booted()}. No `updated_at`.
 *
 * `actor_type` values come from one of customer/admin/system. `actor_user_id`
 * references `users.id` but is hidden from the customer response by the
 * status-history resource (only `actor_type` surfaces; no user_id leak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_status_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('support_ticket_id')
                ->constrained('support_tickets')
                ->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('actor_type', 16);
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['support_ticket_id', 'created_at'],
                'stsh_ticket_created_idx',
            );
            $table->index('actor_user_id', 'stsh_actor_idx');
            $table->index('actor_type', 'stsh_actor_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_status_history');
    }
};
