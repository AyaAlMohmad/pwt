<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Per-machine maintenance event log.
 *
 * One row per scheduled / completed / missed / cancelled maintenance
 * action on a machine. Imported rows from the Excel seeder are always
 * `completed` with `scheduled_at = completed_at` and `source = imported`.
 * Phase 18 admin endpoints will add `manual` and `request` rows.
 *
 * No soft delete — operational history is append-only; admin marks a
 * row `cancelled`, never deletes.
 *
 * See docs/architecture/machines-maintenance.md § 8.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_maintenance_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('machine_id')
                ->constrained('machines')
                ->cascadeOnDelete();
            $table->string('status', 16);
            $table->date('scheduled_at');
            $table->date('completed_at')->nullable();
            $table->foreignId('technician_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('source', 16);
            $table->foreignId('maintenance_request_id')
                ->nullable()
                ->constrained('maintenance_requests')
                ->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('source');
            $table->index(['machine_id', 'scheduled_at'], 'mml_machine_scheduled_idx');
            $table->index(['machine_id', 'status'], 'mml_machine_status_idx');
            $table->index('maintenance_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_maintenance_logs');
    }
};
