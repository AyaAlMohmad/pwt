<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `files` table — the umbrella resource for every uploaded
 * artefact in the platform (profile avatars, maintenance media, support
 * attachments, order attachments, product images, generic uploads).
 *
 * See docs/architecture/files.md § 8.1 for the column-by-column rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('uuid', 36)->unique();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('disk', 20)->default('public');
            $table->string('path', 255);
            $table->string('filename_original', 255);
            $table->string('mime', 80)->index();
            $table->unsignedBigInteger('size');
            $table->string('type', 40)->index();
            $table->char('checksum_sha256', 64)->nullable()->index();
            $table->boolean('is_public')->default(true);
            $table->softDeletes();
            $table->timestamps();

            // Drives "list my media of type X" queries in Phase 12 / 13.
            $table->index(['user_id', 'type', 'deleted_at'], 'files_user_type_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
