<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('otps', 'purpose')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE otps MODIFY COLUMN purpose VARCHAR(50) NOT NULL');

            return;
        }

        Schema::table('otps', function (Blueprint $table): void {
            $table->string('purpose', 50)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('otps', 'purpose')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE otps MODIFY COLUMN purpose VARCHAR(16) NOT NULL');

            return;
        }

        Schema::table('otps', function (Blueprint $table): void {
            $table->string('purpose', 16)->change();
        });
    }
};
