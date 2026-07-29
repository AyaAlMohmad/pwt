<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `product_images` table — gallery rows for a product.
 *
 * See docs/architecture/catalog.md § 8.3. Image rows are owned by the
 * product (cascade on delete); the admin-only "at most one primary"
 * invariant is enforced at the application layer in Phase 16.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('url', 500);
            $table->json('alt_text')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'is_primary'], 'product_images_primary_idx');
            $table->index(['product_id', 'display_order'], 'product_images_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
