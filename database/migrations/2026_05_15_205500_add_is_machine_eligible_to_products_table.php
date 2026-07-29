<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Products extension.
 *
 * Adds the `is_machine_eligible` boolean to `products`. When an order
 * transitions to `installed`, the Phase 10 listener
 * (`MachineCreationFromOrderService`) only creates machine rows for
 * items whose product is machine-eligible — software upgrades,
 * subscriptions, and other non-physical SKUs are skipped.
 *
 * Default is `true` since every current SKU IS a physical filter unit;
 * the flag exists to future-proof the schema (Phase 2 cartridges /
 * services / etc.).
 *
 * See docs/architecture/machines-maintenance.md § 8.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_machine_eligible')
                ->default(true)
                ->after('is_quote_only');

            $table->index('is_machine_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['is_machine_eligible']);
            $table->dropColumn('is_machine_eligible');
        });
    }
};
