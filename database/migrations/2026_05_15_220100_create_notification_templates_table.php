<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — Notification templates.
 *
 * One row per (event key × channel) pair. Subject + body are
 * translatable JSON columns; `placeholders` is the supported variable
 * list surfaced to Phase 22 admin UI.
 *
 * See docs/architecture/notifications.md § 10.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('key', 64);
            $table->string('channel', 16);

            // Translatable copy. Subject is used as the email subject AND
            // the in-app `notifications.title`. Body supports {{placeholder}}.
            $table->json('subject');
            $table->json('body');

            // Allowed placeholder paths (`order.public_id`, `user.name`, …).
            // Phase 22 admin UI renders an autocomplete from this list.
            $table->json('placeholders')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One template per (event, channel) pair — admin Phase 22 may
            // disable a row instead of deleting it.
            $table->unique(['key', 'channel'], 'notification_templates_key_channel_unique');
            $table->index('is_active', 'notification_templates_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
