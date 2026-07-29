<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * A single gallery image attached to a {@see Product}.
 *
 * `url` is a stored relative path (`products/PW50M/primary.webp`)
 * resolved to an absolute URL at the Resource layer. The
 * "at most one primary per product" invariant is enforced by the
 * Phase 16 admin update flow; this model intentionally does not lock
 * it because read-only Phase 6 traffic can never violate it.
 *
 * Phase 16 adds the optional `file_id` FK back to the originating
 * `files` row. New attaches always populate it; legacy seeded rows
 * may have `file_id=null`. See docs/architecture/admin-catalog.md § 8.2.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $file_id
 * @property string $url
 * @property array<string, string>|string|null $alt_text
 * @property bool $is_primary
 * @property int $display_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product|null $product
 * @property-read File|null $file
 */
final class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    use HasTranslations;

    protected $table = 'product_images';

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'file_id',
        'url',
        'alt_text',
        'is_primary',
        'display_order',
    ];

    /** @var array<int, string> */
    public array $translatable = ['alt_text'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_id' => 'integer',
            'is_primary' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    protected static function newFactory(): ProductImageFactory
    {
        return ProductImageFactory::new();
    }
}
