<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer shopping cart — persistent server-side per user.
 *
 * One cart per user. Items carry product_id, term (buy/rent/foc),
 * and quantity so the client can replay the cart into an order
 * at checkout time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('currency', 3)->default('AED');
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('cart_id')
                ->constrained('carts')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('term', 10);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            // Prevent the same product+term appearing twice in one cart.
            $table->unique(['cart_id', 'product_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
