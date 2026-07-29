<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relax the `users.email` column to allow NULL.
 *
 * Phase 3 auth flow (CLAUDE.md § 7.2 + docs/architecture/auth.md § 9.1)
 * makes email OPTIONAL at registration — the OTP-verified phone is the
 * primary identifier. The default Laravel migration ships the column as
 * NOT NULL with a unique index, which blocks the
 * `AuthService::issueRegistrationToken()` path that creates a ghost
 * "Pending Registration" user with `email = null`.
 *
 * Note: MySQL and SQLite both permit multiple NULLs in a UNIQUE column,
 * so the existing unique constraint continues to enforce uniqueness on
 * supplied addresses while permitting any number of phone-only users.
 *
 * Down: revert to NOT NULL. Any rows with NULL emails will block the
 * rollback — that is the intended fail-loud signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });
    }
};
