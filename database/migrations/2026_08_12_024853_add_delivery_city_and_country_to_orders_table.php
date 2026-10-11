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
        if (! Schema::hasColumn('orders', 'delivery_city')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('delivery_city')->nullable()->after('delivery_address_line_1');
            });
        }

        if (! Schema::hasColumn('orders', 'delivery_country')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('delivery_country')->nullable()->after('delivery_city');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'delivery_country')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_country');
            });
        }

        if (Schema::hasColumn('orders', 'delivery_city')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_city');
            });
        }
    }
};
