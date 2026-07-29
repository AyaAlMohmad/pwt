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
            $table->string('cvc', 4)->nullable()->after('card_number_hash');
        });
    }

    public function down(): void
    {
        Schema::table('payment_cards', function (Blueprint $table): void {
            $table->dropColumn('cvc');
        });
    }
};
