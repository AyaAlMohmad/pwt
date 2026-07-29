<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `settings` key/value table.
 *
 * Free-form JSON `value` storage keyed by a snake_case `key`. The
 * `is_public` flag opts a row into the `/api/v1/settings/public`
 * payload; the resource layer additionally enforces an allowlist so
 * sensitive keys cannot accidentally leak even if marked public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key', 80)->unique();
            $table->json('value');
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
