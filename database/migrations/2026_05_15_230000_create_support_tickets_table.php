<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Support tickets.
 *
 * Customer-submitted support tickets, optionally cross-linked to an
 * order or a machine. State flows through the matrix documented in
 * docs/architecture/support.md § 2.6.
 *
 * Soft-deleted (admin housekeeping); status history is in a separate
 * append-only table so the audit trail is preserved. The first message
 * + attachments live in their own tables — the `subject` column on this
 * row is the ticket summary, not the message body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Optional cross-links. `restrictOnDelete` so a soft-deleted
            // order/machine still surfaces in the ticket history; the
            // hard-delete path requires admin housekeeping first.
            $table->foreignId('related_order_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();
            $table->foreignId('related_machine_id')
                ->nullable()
                ->constrained('machines')
                ->restrictOnDelete();

            $table->string('subject', 200);
            $table->string('category', 32);
            $table->string('priority', 16)->default('medium');
            $table->string('status', 16)->default('open');

            // `nullOnDelete` not `restrictOnDelete` — a deactivated admin
            // user shouldn't block ticket history queries.
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('resolution_summary')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes per docs/architecture/support.md § 11.1.
            $table->index('status');
            $table->index('priority');
            $table->index('category');
            $table->index('opened_at');
            $table->index('deleted_at');
            $table->index(['user_id', 'status'], 'st_user_status_idx');
            $table->index(['user_id', 'category'], 'st_user_category_idx');
            $table->index(['user_id', 'created_at'], 'st_user_created_idx');
            $table->index(['status', 'priority', 'created_at'], 'st_status_priority_created_idx');
            $table->index(['assigned_to_user_id', 'status'], 'st_assignee_status_idx');
            $table->index('related_order_id');
            $table->index('related_machine_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
