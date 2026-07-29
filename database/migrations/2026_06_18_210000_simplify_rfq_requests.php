<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->dropColumn([
                'trade_license_file_id',
                'contact_name',
                'job_title',
                'business_email',
                'phone_country_code',
                'preferred_products',
                'total_quantity',
                'required_by',
                'installation_location',
            ]);

            $table->string('email', 200)->after('company_name');
            $table->string('ip', 45)->nullable()->after('notes');
            $table->string('user_agent', 255)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('trade_license_file_id', 36)->nullable()->after('company_name');
            $table->string('contact_name', 120)->after('trade_license_file_id');
            $table->string('job_title', 120)->nullable()->after('contact_name');
            $table->string('business_email', 200)->index()->after('job_title');
            $table->string('phone_country_code', 6)->nullable()->after('business_email');
            $table->json('preferred_products')->nullable()->after('phone');
            $table->string('total_quantity', 60)->nullable()->after('preferred_products');
            $table->string('required_by', 60)->nullable()->after('total_quantity');
            $table->text('installation_location')->nullable()->after('required_by');

            $table->dropColumn(['email', 'ip', 'user_agent']);
        });
    }
};
