<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Order installation appointments.
 *
 * One installation row per order (enforced via the unique index on
 * `order_id`). Phase 8 writes the row when an admin transitions
 * confirmed → scheduled (Phase 17 wires that endpoint); Phase 10
 * subscribes to the installed-state event to create the machine record.
 *
 * `address_snapshot` freezes the delivery address as it stood at
 * scheduling time so post-edit changes never break the technician's
 * record.
 *
 * See docs/architecture/orders.md § 8.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_installations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('order_id')
                ->unique()
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->timestamp('scheduled_for_at');
            $table->foreignId('technician_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('installation_notes')->nullable();
            $table->json('address_snapshot')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('technician_user_id');
            $table->index('scheduled_for_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_installations');
    }
};
