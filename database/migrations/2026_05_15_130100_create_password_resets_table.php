<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password-reset workflow table.
 *
 * Two channels are supported:
 *  - `email`: 64-hex token mailed to the user; bcrypt-hashed in `token_hash`.
 *  - `phone`: parallel `otps` row carries the proof; `token_hash` is NULL.
 *
 * `user_id` is nullable to allow anti-enumeration rows for unknown
 * identifiers (the controller may still write a placeholder row to
 * keep timing constant; service-layer policy decides).
 *
 * NB: The default Laravel `password_reset_tokens` table created by
 * the framework's bootstrap migration is left untouched; the PWT
 * application logic uses ONLY this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 8);
            $table->string('identifier', 160);
            $table->string('token_hash')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['identifier', 'channel', 'consumed_at'], 'password_resets_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
