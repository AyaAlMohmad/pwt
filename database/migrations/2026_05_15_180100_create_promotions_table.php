<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `promotions` table — promo-code definitions.
 *
 * See docs/architecture/promotions.md § 8.2. `type` is a string column
 * backed by {@see DiscountType}; `applies_to` is backed by
 * {@see PromoScope}. Money columns are `decimal(12, 2)` per CLAUDE.md
 * § 6. `code` is stored upper-case + trimmed by the service layer; the
 * unique index enforces a single canonical row per code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 40)->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('type', 10);
            $table->decimal('value', 12, 2);
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable()->default(1);
            $table->unsignedInteger('total_limit')->nullable();
            $table->string('applies_to', 15)->default('all');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('is_active');
            $table->index(['starts_at', 'ends_at'], 'promotions_window_idx');
            $table->index(['is_active', 'starts_at', 'ends_at'], 'promotions_active_window_idx');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
