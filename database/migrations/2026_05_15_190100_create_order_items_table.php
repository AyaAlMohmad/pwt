<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Order line items.
 *
 * One row per cart line. Snapshot columns (`product_code`,
 * `product_name_snapshot`, `unit_price`, `currency`, `rent_period`,
 * `rent_min_term_months`) freeze the product context at order time so
 * downstream renderings stay correct even if the product is renamed,
 * deactivated, or its prices change.
 *
 * `product_name_snapshot` is a bilingual JSON column (Spatie
 * HasTranslations) so order history renders correctly in both en/ar.
 *
 * See docs/architecture/orders.md § 8.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->string('product_code', 80);
            $table->json('product_name_snapshot');

            $table->string('term', 10);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->string('currency', 3);

            $table->decimal('line_subtotal', 12, 2);
            $table->decimal('line_discount', 12, 2)->default(0);
            $table->decimal('line_tax', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);

            $table->string('rent_period', 20)->nullable();
            $table->unsignedSmallInteger('rent_min_term_months')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
