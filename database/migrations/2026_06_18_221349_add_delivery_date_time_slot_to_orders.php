<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->date('delivery_date')->nullable()->after('notes');
            $table->string('delivery_time_slot', 20)->nullable()->after('delivery_date')
                ->comment('morning, afternoon, evening');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['delivery_date', 'delivery_time_slot']);
        });
    }
};
