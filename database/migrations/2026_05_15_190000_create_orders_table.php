<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Orders.
 *
 * Creates the `orders` table. See docs/architecture/orders.md § 8.1 for
 * the column-by-column rationale. All monetary fields are
 * `decimal(12,2)` per CLAUDE.md § 6 and are server-recomputed; we never
 * trust client input for prices.
 *
 * The `(user_id, idempotency_key)` pair has a regular composite index
 * rather than a unique constraint — see § 9.4 of the architecture doc:
 * application-level 24h replay window beats a strict unique constraint
 * because it keeps idempotency-key history queryable for audit without
 * polluting the active key space.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('delivery_address_id')
                ->constrained('addresses')
                ->restrictOnDelete();
            $table->foreignId('billing_address_id')
                ->nullable()
                ->constrained('addresses')
                ->restrictOnDelete();

            $table->foreignId('promotion_id')
                ->nullable()
                ->constrained('promotions')
                ->restrictOnDelete();

            $table->string('term', 10);
            $table->string('payment_method', 20);
            $table->string('status', 20)->default('pending');

            $table->string('currency', 3)->default('AED');
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // Idempotency: optional client-supplied key + sha256 hash of the
            // canonicalised request body. Non-unique index keeps the 24h
            // application-level window cheap (§ 1.5 / § 9.4).
            $table->string('idempotency_key', 80)->nullable();
            $table->char('idempotency_request_hash', 64)->nullable();

            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('scheduled_for_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Drives the most common list query (caller + status filter).
            $table->index(['user_id', 'status'], 'orders_user_status_idx');
            // Drives the default sort (created_at DESC, scoped to the
            // caller's order history).
            $table->index(['user_id', 'created_at'], 'orders_user_created_idx');
            // Idempotency lookup — see § 9.4 (regular index, not unique).
            $table->index(['user_id', 'idempotency_key'], 'orders_user_idem_idx');
            $table->index('status');
            $table->index('promotion_id');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
