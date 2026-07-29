<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('discount_percentage', 5, 2)->nullable()->after('is_quote_only')
                ->comment('Discount % (e.g. 10.00 means 10% off)');
            $table->decimal('original_price', 12, 2)->nullable()->after('discount_percentage')
                ->comment('Original price before discount');
            $table->string('tag_label', 30)->nullable()->after('original_price')
                ->comment('e.g. new, best_seller');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['discount_percentage', 'original_price', 'tag_label']);
        });
    }
};
