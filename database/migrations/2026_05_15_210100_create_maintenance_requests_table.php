<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Maintenance requests.
 *
 * Customer-submitted service requests, always tied to one
 * `machines.id`. Status flows through the matrix documented in
 * docs/architecture/machines-maintenance.md § 2.3.
 *
 * Soft-deleted (admin housekeeping); status history is in a separate
 * append-only table so the audit trail is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('machine_id')
                ->constrained('machines')
                ->restrictOnDelete();

            $table->string('issue_type', 32);
            $table->text('description');
            $table->string('status', 16)->default('new');
            $table->string('priority', 8)->default('medium');
            $table->date('preferred_date')->nullable();

            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('issue_type');
            $table->index('status');
            $table->index('priority');
            $table->index('deleted_at');
            $table->index(['user_id', 'status', 'created_at'], 'mr_user_status_created_idx');
            $table->index(['machine_id', 'created_at'], 'mr_machine_created_idx');
            $table->index(['assigned_to_user_id', 'status'], 'mr_assignee_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
    }
};
