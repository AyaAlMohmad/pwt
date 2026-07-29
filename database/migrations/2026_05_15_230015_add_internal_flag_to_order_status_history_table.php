<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 — Admin Orders.
 *
 * Adds `is_internal` boolean to `order_status_history` so admin-only
 * notes (notes-only rows where `from_status === to_status`) can be
 * filtered out of customer-facing timeline responses. Widens the `note`
 * column from 500 → 2000 to accommodate the longer admin notes payload
 * specified in docs/architecture/admin-orders.md § 3.5.
 *
 * See docs/architecture/admin-orders.md § 9.4 and § 17.3 of the
 * orchestrator decisions for the rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_history', function (Blueprint $table): void {
            // Default false so existing customer / system rows remain
            // visible on the customer surface. Admin-only notes flip
            // this to true at write-time.
            $table->boolean('is_internal')->default(false)->after('note');

            // Wider note column for admin standalone notes (max 2000
            // per § 3.5). Transition notes still cap at 1000 in the
            // Form Request layer.
            $table->string('note', 2000)->nullable()->change();

            // Composite index so the customer timeline filter
            // (`order_id` + `is_internal = false`) is index-covered.
            $table->index(['order_id', 'is_internal'], 'osh_order_internal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_status_history', function (Blueprint $table): void {
            $table->dropIndex('osh_order_internal_idx');
            $table->string('note', 500)->nullable()->change();
            $table->dropColumn('is_internal');
        });
    }
};
