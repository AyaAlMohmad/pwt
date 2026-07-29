<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `testimonials` table — customer quotes for the home page.
 *
 * See docs/architecture/promotions.md § 8.6. Only `quote` is translatable
 * in Phase 7 (the orchestrator decision is documented in § 9.7). `role`
 * / `company` are plain strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 100);
            $table->string('role', 100)->nullable();
            $table->string('company', 120)->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->unsignedTinyInteger('rating')->default(5);
            $table->json('quote');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'display_order'], 'testimonials_listing_idx');
            $table->index('rating');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
