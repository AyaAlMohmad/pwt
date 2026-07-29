<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Pivot linking maintenance requests to Phase 4 files.
 *
 * `file_id` uses `restrictOnDelete` so the Phase 4 `FileService::delete`
 * is required to detach pivot rows before purging the file row (prevents
 * orphaned media references). Pivot is immutable — no `updated_at`.
 *
 * UNIQUE(maintenance_request_id, file_id) so the same file cannot be
 * attached twice to the same request. Custom OwnedFile validation rule
 * additionally ensures one-shot attachment across DIFFERENT requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_request_media', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('maintenance_request_id')
                ->constrained('maintenance_requests')
                ->cascadeOnDelete();
            $table->foreignId('file_id')
                ->constrained('files')
                ->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['maintenance_request_id', 'file_id'],
                'mrm_request_file_unique',
            );
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_request_media');
    }
};
