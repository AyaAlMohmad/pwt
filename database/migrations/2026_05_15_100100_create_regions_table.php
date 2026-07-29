<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `regions` reference table.
 *
 * One row per administrative division within a country (emirate,
 * province, governorate). Bilingual `name`, optional short `code`
 * which is unique within a single country only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('country_id')
                ->constrained('countries')
                ->restrictOnDelete();
            $table->string('code', 8)->nullable();
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country_id', 'is_active']);
            $table->index(['country_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
