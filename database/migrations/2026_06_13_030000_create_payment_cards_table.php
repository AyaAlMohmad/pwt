<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('brand', 30)->nullable();
            $table->string('last_four', 4);
            $table->string('card_number_hash', 64)->nullable();
            $table->string('expiry_month', 2);
            $table->string('expiry_year', 4);
            $table->string('cardholder_name', 120)->nullable();
            $table->boolean('is_default')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'is_default'], 'payment_cards_user_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_cards');
    }
};
