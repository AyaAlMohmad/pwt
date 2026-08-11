<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_cards', function (Blueprint $table): void {
            $table->string('stripe_payment_method_id', 255)->nullable()->after('last_four');
            $table->string('stripe_customer_id', 255)->nullable()->after('stripe_payment_method_id');

            $table->dropColumn('card_number_hash');
            $table->dropColumn('cvc');
        });
    }

    public function down(): void
    {
        Schema::table('payment_cards', function (Blueprint $table): void {
            $table->dropColumn('stripe_payment_method_id');
            $table->dropColumn('stripe_customer_id');

            $table->string('card_number_hash', 64)->nullable()->after('last_four');
            $table->string('cvc', 4)->nullable()->after('card_number_hash');
        });
    }
};
