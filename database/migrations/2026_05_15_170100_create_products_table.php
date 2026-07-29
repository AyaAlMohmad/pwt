<?php

declare(strict_types=1);

use App\Enums\StockStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `products` table — individual SKU rows under a category.
 *
 * See docs/architecture/catalog.md § 8.2. `code` is the SKU model code
 * lifted directly from the Excel "Unit Type" column (e.g. `PW50M`); it
 * is the bridge identifier consumed by Phase 10's
 * `MachinesFromExcelSeeder`. `stock_status` is a string column (not
 * MySQL ENUM, per CLAUDE.md § 6) backed by {@see StockStatus}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();
            $table->string('code', 80)->unique();
            $table->json('name');
            $table->json('short_description')->nullable();
            $table->json('description')->nullable();
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_quote_only')->default(false);
            $table->string('stock_status', 20)->default('in_stock');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('is_active');
            $table->index('is_featured');
            $table->index('stock_status');
            $table->index('deleted_at');
            $table->index(['is_active', 'category_id'], 'products_active_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
