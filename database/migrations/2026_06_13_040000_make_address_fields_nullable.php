<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->foreignId('country_id')->nullable()->change();
            $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();

            $table->string('recipient_name', 120)->nullable()->change();
            $table->string('recipient_phone', 20)->nullable()->change();
            $table->string('recipient_phone_country_code', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
            $table->foreignId('country_id')->change();
            $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();

            $table->string('recipient_name', 120)->nullable(false)->change();
            $table->string('recipient_phone', 20)->nullable(false)->change();
            $table->string('recipient_phone_country_code', 6)->nullable(false)->change();
        });
    }
};
