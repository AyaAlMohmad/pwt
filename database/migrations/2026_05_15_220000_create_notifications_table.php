<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — Notifications inbox + delivery audit.
 *
 * One row per (recipient × channel) hand-off. `in_app` rows ARE the
 * customer inbox surface; other channels carry the audit trail for
 * email / SMS / WhatsApp / push transmissions. NO soft deletes —
 * `notifications` is not in the `CLAUDE.md` § 6 soft-delete list.
 *
 * See docs/architecture/notifications.md § 10.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Event template key — e.g. `order.confirmed`,
            // `maintenance.assigned`. Indexed for the `?type=` filter.
            $table->string('type', 64);

            // Translatable JSON columns ({ "en": "...", "ar": "..." }).
            $table->json('title');
            $table->json('body')->nullable();

            // Safe scalar context — never raw Eloquent serialisations.
            $table->json('data')->nullable();
            $table->string('action_url', 2048)->nullable();

            // Channel + delivery_status enums stored as strings; the PHP
            // enum casts live on the model.
            $table->string('channel', 16);
            $table->string('delivery_status', 16)->default('queued');
            $table->string('failure_reason', 500)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // Indexes — chosen to cover the GET /notifications query
            // shapes (default sort, unread filter, type filter) plus the
            // Phase 22 admin delivery reporting (sent_at).
            $table->index(['user_id', 'created_at'], 'notifications_user_created_idx');
            $table->index(['user_id', 'read_at'], 'notifications_user_read_idx');
            $table->index(['user_id', 'type'], 'notifications_user_type_idx');
            $table->index('channel', 'notifications_channel_idx');
            $table->index('sent_at', 'notifications_sent_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
