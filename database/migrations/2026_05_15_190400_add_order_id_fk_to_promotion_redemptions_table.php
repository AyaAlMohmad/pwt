<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Promote `promotion_redemptions.order_id` from an unconstrained
 * nullable column to a real foreign key against `orders`.
 *
 * The column was introduced in Phase 7 (see
 * 2026_05_15_180400_create_promotion_redemptions_table.php) but left
 * unconstrained because the `orders` table did not yet exist. Phase 8
 * now wires the constraint with `nullOnDelete` so historical redemption
 * rows survive an admin hard-delete of the parent order (defence in
 * depth — orders are soft-deleted in practice).
 *
 * Existing index on `order_id` is preserved.
 *
 * See docs/architecture/orders.md § 8.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_redemptions', function (Blueprint $table): void {
            $table->foreign('order_id', 'promotion_redemptions_order_id_foreign')
                ->references('id')
                ->on('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promotion_redemptions', function (Blueprint $table): void {
            $table->dropForeign('promotion_redemptions_order_id_foreign');
        });
    }
};
