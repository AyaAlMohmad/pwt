<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — Payments.
 *
 * Creates the `payments` table. See docs/architecture/payments.md § 10.1
 * for the column-by-column rationale.
 *
 * The `gateway_transaction_id` column carries a UNIQUE index — gateway
 * correlator ids are unique per init and the webhook idempotency check
 * relies on it. `user_id` is denormalised from `orders.user_id` to make
 * ownership lookups index-only (Policy hot path).
 *
 * No `expires_at` column — it is reconstructed in the API resource from
 * `created_at + initiated_ttl_minutes` (config), keeping the schema
 * config-driven and avoiding a column that can drift out of sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            // Denormalised from orders.user_id — see § 10.1.
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('payment_method', 20);
            $table->string('gateway', 20);

            // Unique gateway correlator. Nullable for the brief window
            // between Payment INSERT and the driver returning the id.
            $table->string('gateway_transaction_id', 120)->nullable()->unique();
            $table->string('gateway_reference', 120)->nullable();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('AED');

            $table->string('status', 20)->default('initiated');
            $table->string('failure_reason', 500)->nullable();

            // Hosted-checkout URL returned by the gateway. Null for COD.
            $table->string('client_payment_url', 1024)->nullable();

            $table->string('idempotency_key', 80)->nullable();

            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Hot lookup: ownership scope (Policy / list).
            $table->index(['user_id', 'status'], 'payments_user_status_idx');
            // Hot lookup: "already initiated" guard on initiate.
            $table->index(['order_id', 'status'], 'payments_order_status_idx');
            // Hot lookup: idempotency replay (24h application window).
            $table->index(['user_id', 'idempotency_key'], 'payments_user_idem_idx');
            // Hot lookup: gateway filter (admin Phase 17).
            $table->index('gateway');
            $table->index('payment_method');
            $table->index('created_at');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
