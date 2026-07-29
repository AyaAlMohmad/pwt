<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Customer contact info (user can override profile values at checkout)
            $table->string('customer_name', 120)->nullable()->after('user_id');
            $table->string('customer_email', 255)->nullable()->after('customer_name');
            $table->string('customer_phone', 20)->nullable()->after('customer_email');

            // Delivery address entered manually (alternative to selecting saved address)
            $table->string('delivery_address_line_1', 255)->nullable()->after('delivery_address_id');
            $table->string('delivery_city', 120)->nullable()->after('delivery_address_line_1');
            $table->string('delivery_postcode', 20)->nullable()->after('delivery_city');

            // Installation preferred time slot
            $table->string('installation_preferred_time', 20)->nullable()->after('notes')
                ->comment('morning, afternoon, evening, etc.');

            // Payment card (only last-four + expiry are stored; raw number & CVC are NEVER persisted)
            $table->string('card_last_four', 4)->nullable()->after('installation_preferred_time');
            $table->string('card_expiry_month', 2)->nullable()->after('card_last_four');
            $table->string('card_expiry_year', 4)->nullable()->after('card_expiry_month');

            // Make delivery_address_id nullable so typed addresses are accepted
            $table->dropForeign(['delivery_address_id']);
            $table->foreignId('delivery_address_id')->nullable()->change();
            $table->foreign('delivery_address_id')->references('id')->on('addresses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_name',
                'customer_email',
                'customer_phone',
                'delivery_address_line_1',
                'delivery_city',
                'delivery_postcode',
                'installation_preferred_time',
                'card_last_four',
                'card_expiry_month',
                'card_expiry_year',
            ]);

            $table->dropForeign(['delivery_address_id']);
            $table->foreignId('delivery_address_id')->nullable(false)->change();
            $table->foreign('delivery_address_id')->references('id')->on('addresses')->restrictOnDelete();
        });
    }
};
