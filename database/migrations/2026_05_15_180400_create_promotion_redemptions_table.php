<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `promotion_redemptions` table — immutable audit of every
 * applied promotion.
 *
 * See docs/architecture/promotions.md § 8.5. The table is created empty
 * in Phase 7: no code writes to it until Phase 8's `OrderService` runs
 * the redemption inside the order-creation transaction.
 *
 * `order_id` is nullable here (Phase 7 future-proofing per the
 * orchestrator decision); Phase 8 populates it. `restrictOnDelete` on
 * `promotion_id` / `user_id` so historical redemption records cannot be
 * orphaned — admins must soft-delete instead of hard-deleting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('promotion_id')
                ->constrained('promotions')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            // `order_id` is intentionally NOT constrained in Phase 7
            // because the `orders` table does not exist yet (Phase 8).
            // Phase 8 adds the FK constraint via a follow-up migration.
            $table->foreignId('order_id')->nullable();
            $table->decimal('amount_applied', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->index(['promotion_id', 'user_id'], 'promotion_redemptions_user_idx');
            $table->index('promotion_id');
            $table->index('order_id');
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
    }
};
