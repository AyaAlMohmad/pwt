<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `addresses` table — customer address book entries.
 *
 * One row per delivery destination owned by a customer. The customer
 * picks one of these rows at checkout (Phase 8 will reference
 * `addresses.id` as `orders.delivery_address_id`).
 *
 * See docs/architecture/addresses.md § 8.1 for the column-by-column
 * rationale and § 9.4 for the concurrency model that backs the
 * `is_default` invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('country_id')
                ->constrained('countries')
                ->restrictOnDelete();
            $table->foreignId('region_id')
                ->nullable()
                ->constrained('regions')
                ->restrictOnDelete();
            $table->string('label', 60);
            $table->string('recipient_name', 120);
            $table->string('recipient_phone', 20);
            $table->string('recipient_phone_country_code', 6);
            $table->string('line1', 200);
            $table->string('line2', 200)->nullable();
            $table->string('city', 100);
            $table->string('area', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Drives the per-user "find the current default" lookup that
            // runs inside the demote-others transition. See § 2.3.
            $table->index(['user_id', 'is_default'], 'addresses_user_default_idx');

            // Supports the SoftDeletes global scope + the address-cap
            // query (which must exclude trashed rows).
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
