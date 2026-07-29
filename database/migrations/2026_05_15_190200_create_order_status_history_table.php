<?php

declare(strict_types=1);

use App\Models\OrderStatusHistory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Order status history (customer-visible audit trail).
 *
 * Append-only timeline of every status transition for an order. Read
 * by `GET /orders/{id}` (full detail) and `GET /orders/{id}/tracking`
 * (computed projection). See docs/architecture/orders.md § 8.3.
 *
 * Immutability is enforced at the model level
 * ({@see OrderStatusHistory::booted()}); no `updated_at`
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('actor_type', 10);
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('note', 500)->nullable();
            // Append-only — no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at'], 'osh_order_created_idx');
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
