<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_leads', function (Blueprint $table): void {
            $table->string('type', 16)->default('general')->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('contact_leads', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
