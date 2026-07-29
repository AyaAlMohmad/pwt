<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `categories` table — top-level groupings for the product catalog.
 *
 * See docs/architecture/catalog.md § 8.1 for the column-by-column
 * rationale. `parent_id` is reserved for future sub-category support
 * (Phase 2) but the customer API hides the column. Soft deletes are
 * enabled per CLAUDE.md § 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();
            $table->string('code', 80)->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('display_order');
            $table->index('is_active');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
