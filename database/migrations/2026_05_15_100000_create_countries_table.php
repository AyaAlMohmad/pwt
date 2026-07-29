<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `countries` reference table.
 *
 * Stores the GCC country list with bilingual names, phone prefix,
 * currency code, and the default VAT rate used for order totals.
 *
 * Soft deletes are intentionally omitted (see CLAUDE.md § 6): admins
 * toggle `is_active` rather than delete reference rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 2)->unique();
            $table->json('name');
            $table->string('phone_code', 8);
            $table->string('currency', 3);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
