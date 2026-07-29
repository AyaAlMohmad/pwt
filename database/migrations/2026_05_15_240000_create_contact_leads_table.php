<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — Contact Form / Guest Leads.
 *
 * Stores guest contact-form submissions. Phase 22 admin will surface
 * the rows for review (list + mark-processed). This phase only writes.
 *
 * See docs/architecture/contact.md § 8.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_leads', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('name', 120);
            $table->string('email', 200)->index();
            $table->string('phone', 30)->nullable();
            $table->string('phone_country_code', 6)->nullable();

            $table->string('subject', 200);
            $table->text('message');

            // Plain string column — PHP-backed enum `ContactLeadSource`.
            // No MySQL ENUM per CLAUDE.md § 6.
            $table->string('source', 16)->default('web');

            // Server-captured request metadata (never trusted from body).
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Phase 22 admin mark-processed flag.
            $table->boolean('processed')->default(false)->index();
            $table->foreignId('processed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // Backs the Phase 22 default list sort (newest first).
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_leads');
    }
};
