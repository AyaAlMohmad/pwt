<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `file_id` foreign key to `product_images`.
 *
 * Phase 16 (Admin Catalog) attaches a `product_image` row from a
 * pre-uploaded `files` row (FileType::ProductImage). The `url` column
 * snapshots `files.path` so the customer-side render path stays cheap,
 * but the explicit FK lets the admin dashboard answer "what file backs
 * this image?" and enables future orphan-cleanup jobs.
 *
 * Existing rows (seeded sample data without an originating `files` row)
 * keep `file_id = NULL`. `nullOnDelete()` mirrors the lifecycle: when a
 * file is hard-deleted, the image link is severed but the image row
 * persists with the snapshot `url`.
 *
 * See docs/architecture/admin-catalog.md § 8.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->foreignId('file_id')
                ->nullable()
                ->after('product_id')
                ->constrained('files')
                ->nullOnDelete();

            $table->index('file_id', 'product_images_file_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropIndex('product_images_file_id_idx');
            $table->dropForeign(['file_id']);
            $table->dropColumn('file_id');
        });
    }
};
