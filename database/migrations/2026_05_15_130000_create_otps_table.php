<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OTP storage for the customer auth module (register, login, reset).
 *
 * Hash semantics: `code_hash` is the bcrypt of the cleartext 6-digit
 * value — the plaintext code is NEVER stored. The OTP plaintext leaves
 * the system only via the SMS dispatcher (and the `dev_otp` field in
 * non-production envs).
 *
 * State machine (derived; not a stored column):
 *   - active   : consumed_at IS NULL AND expires_at > now() AND attempts < max_attempts
 *   - consumed : consumed_at IS NOT NULL
 *   - exhausted: consumed_at IS NOT NULL AND attempts >= max_attempts (3 wrong tries)
 *   - expired  : expires_at <= now()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otps', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('identifier', 32);
            $table->string('purpose', 16);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['identifier', 'purpose', 'consumed_at'], 'otps_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
