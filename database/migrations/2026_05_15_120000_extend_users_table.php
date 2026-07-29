<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the `users` table with PWT-specific columns.
 *
 * - phone / phone_country_code: primary auth identifier in the OTP flow
 *   (see CLAUDE.md § 7.2). The combined (country_code, phone) pair is
 *   unique across the table.
 * - locale: preferred UI language ('en' or 'ar'), driving outbound SMS
 *   / email content even when the request header is absent.
 * - is_active: soft-disable flag for admin-deactivated accounts.
 * - deleted_at: soft deletes per CLAUDE.md § 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_country_code', 8)->default('+971')->after('email');
            $table->string('phone', 20)->nullable()->after('phone_country_code');
            $table->string('locale', 5)->default('en')->after('phone');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->softDeletes();

            $table->unique(['phone_country_code', 'phone'], 'users_phone_unique');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_phone_unique');
            $table->dropIndex(['is_active']);
            $table->dropSoftDeletes();
            $table->dropColumn(['phone_country_code', 'phone', 'locale', 'is_active']);
        });
    }
};
