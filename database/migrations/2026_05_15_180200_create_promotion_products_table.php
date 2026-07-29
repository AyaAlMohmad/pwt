<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `promotion_products` pivot — scope-restriction list for
 * promotions with `applies_to=products`.
 *
 * See docs/architecture/promotions.md § 8.3. Cascades on delete from
 * either side: removing a promotion or a product cleans up the pivot
 * rows automatically (no audit value in retaining them — the parent
 * `promotion_redemptions` rows preserve the historical record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('promotion_id')
                ->constrained('promotions')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['promotion_id', 'product_id'], 'promotion_products_unique_idx');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_products');
    }
};
