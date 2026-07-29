<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table): void {
            $table->string('voltage', 10)->nullable()->after('notes');
            $table->foreignId('address_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table): void {
            $table->dropColumn('voltage');
            $table->foreignId('address_id')->nullable(false)->change();
        });
    }
};
