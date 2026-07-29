<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `promotion_categories` pivot — scope-restriction list for
 * promotions with `applies_to=categories`.
 *
 * See docs/architecture/promotions.md § 8.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('promotion_id')
                ->constrained('promotions')
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['promotion_id', 'category_id'], 'promotion_categories_unique_idx');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_categories');
    }
};
