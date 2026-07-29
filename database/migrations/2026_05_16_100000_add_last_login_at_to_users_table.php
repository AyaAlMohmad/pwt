<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `last_login_at` to the users table.
 *
 * Tracks the most recent successful authentication. For Phase 14 the
 * column is written by AdminAuthService on admin login only; the
 * customer login flow will be extended in a follow-up patch
 * (docs/architecture/admin-auth.md § 9.4).
 *
 * Indexed so the admin "inactive staff" report (Phase 18+) can sort /
 * filter without a table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_login_at']);
            $table->dropColumn('last_login_at');
        });
    }
};
