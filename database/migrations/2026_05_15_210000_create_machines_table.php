<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Machines.
 *
 * One row per physical filtration unit owned by a customer. Created
 * either by the production seeder (`source = imported`) from the
 * `Database for devlpt.xlsx` rows, or at runtime by the
 * `MachineCreationFromOrderService` when an order reaches `installed`
 * (`source = created`).
 *
 * `country_code` is denormalised from the address country so the
 * common `WHERE country_code = ?` machine listing query never has to
 * join. `serial_number` is UNIQUE — runtime-created rows use a
 * `PENDING-<order public_id>-<line>-<qty>` sentinel until admin Phase
 * 18 fills in the real serial.
 *
 * See docs/architecture/machines-maintenance.md § 8.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('address_id')
                ->constrained('addresses')
                ->restrictOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->string('serial_number', 80)->unique();
            $table->string('term', 10);

            $table->date('installed_at');
            $table->unsignedTinyInteger('filter_change_interval_months')->nullable();
            $table->date('next_maintenance_due_at')->nullable();

            $table->string('location_label', 160)->nullable();
            $table->string('country_code', 2);
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('source', 16);

            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();
            $table->foreignId('order_installation_id')
                ->nullable()
                ->constrained('order_installations')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('country_code');
            $table->index('source');
            $table->index('next_maintenance_due_at');
            $table->index('deleted_at');
            $table->index('order_id');
            $table->index(
                ['user_id', 'is_active', 'next_maintenance_due_at'],
                'machines_user_active_due_idx',
            );
            $table->index(['user_id', 'created_at'], 'machines_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
