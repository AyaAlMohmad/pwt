<?php

declare(strict_types=1);

use App\Enums\BannerPlacement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `banners` table — homepage / landing-page promotional banners.
 *
 * See docs/architecture/promotions.md § 8.1 for the column-by-column
 * rationale. `placement` is a string column backed by {@see BannerPlacement}.
 * Title / subtitle / cta_label are translatable JSON columns; image_url
 * and image_url_ar are two explicit columns (rather than translatable
 * JSON) because banner artwork often needs a fully different RTL image
 * — see § 4.1.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 80)->unique();
            $table->string('placement', 20);
            $table->json('title')->nullable();
            $table->json('subtitle')->nullable();
            $table->string('image_url', 500);
            $table->string('image_url_ar', 500)->nullable();
            $table->json('cta_label')->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['placement', 'is_active', 'display_order'], 'banners_listing_idx');
            $table->index(['starts_at', 'ends_at'], 'banners_window_idx');
            $table->index('is_active');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
