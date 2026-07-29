<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFQ (Request for Quotation) table — company-customer only.
 *
 * Separate from `contact_leads` because this is an authenticated,
 * logged-in customer flow with richer product + commercial fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfq_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Authenticated customer (must be account_type = company).
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('company_name', 200);
            $table->string('trade_license_file_id', 36)->nullable();

            $table->string('contact_name', 120);
            $table->string('job_title', 120)->nullable();
            $table->string('business_email', 200)->index();
            $table->string('phone_country_code', 6)->nullable();
            $table->string('phone', 30)->nullable();

            // Preferred products — JSON array of product IDs or codes.
            $table->json('preferred_products')->nullable();
            $table->string('total_quantity', 60)->nullable();
            $table->string('required_by', 60)->nullable();
            $table->text('installation_location')->nullable();
            $table->text('notes')->nullable();

            // Admin review state (mirrors contact_leads pattern).
            $table->string('status', 16)->default('pending')->index();
            $table->boolean('processed')->default(false)->index();
            $table->foreignId('processed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_requests');
    }
};
