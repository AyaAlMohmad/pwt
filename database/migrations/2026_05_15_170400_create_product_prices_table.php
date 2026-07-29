<?php

declare(strict_types=1);

use App\Enums\ProductTerm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `product_prices` table — one row per (product, term, currency).
 *
 * See docs/architecture/catalog.md § 8.5. Money is `decimal(12,2)` per
 * CLAUDE.md § 6 (never float). `term` is a string column backed by
 * {@see ProductTerm}; the unique index guarantees a single
 * canonical price per (product, term, currency) tuple so the customer
 * detail endpoint can render the per-currency toggle deterministically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('term', 10);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('AED');
            $table->string('period', 20)->nullable();
            $table->unsignedSmallInteger('min_term_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'term', 'currency'], 'product_prices_unique_idx');
            $table->index(['product_id', 'is_active'], 'product_prices_active_idx');
            $table->index(['currency', 'amount'], 'product_prices_amount_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
