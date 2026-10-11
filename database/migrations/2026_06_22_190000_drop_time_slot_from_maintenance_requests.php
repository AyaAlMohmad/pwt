<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop time_slot column from maintenance_requests table (replaced by preferred_time).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The column only exists on databases migrated from the
        // pre-rename schema — fresh installs never had it.
        if (! Schema::hasColumn('maintenance_requests', 'time_slot')) {
            return;
        }

        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->dropColumn('time_slot');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->string('time_slot', 16)->nullable()->after('preferred_date');
        });
    }
};
