<?php

declare(strict_types=1);

use App\Enums\MaintenanceActorType;
use App\Models\MaintenanceStatusHistory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Append-only timeline of maintenance request transitions.
 *
 * Mirrors `order_status_history`. Immutability is enforced at the model
 * level via {@see MaintenanceStatusHistory::booted()}. No `updated_at`.
 *
 * `actor_type` values come from the {@see MaintenanceActorType}
 * enum (customer / admin / system). `actor_id` references `users.id` but
 * is hidden from the customer response by `MaintenanceActorResource`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_status_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('maintenance_request_id')
                ->constrained('maintenance_requests')
                ->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('actor_type', 16);
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['maintenance_request_id', 'created_at'],
                'msh_request_created_idx',
            );
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_status_history');
    }
};
