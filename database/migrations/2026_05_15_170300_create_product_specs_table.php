<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `product_specs` table — label/value specification rows.
 *
 * See docs/architecture/catalog.md § 8.4. Both `label` and `value` are
 * translatable JSON columns so admin can publish Arabic-numeral values
 * (e.g. "٥" instead of "5") for the AR locale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_specs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->json('label');
            $table->json('value');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'display_order'], 'product_specs_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specs');
    }
};
