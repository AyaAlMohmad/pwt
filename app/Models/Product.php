<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

/**
 * Catalog product — single SKU under a {@see Category}.
 *
 * `code` is the bridge identifier between this table, the Excel seed
 * sheet (Phase 6's `ProductCatalogSeeder`), and Phase 10's
 * `MachinesFromExcelSeeder` — both of which resolve a product row by
 * its SKU model code, not by numeric id.
 *
 * The `benefits` column is **translatable AND array-valued**: a single
 * row stores `{ "en": ["..."], "ar": ["..."] }`. Spatie's
 * `HasTranslations` trait handles the per-locale projection; the
 * Resource layer flattens to the active-locale array.
 *
 * Customer scoping uses {@see scopeVisibleToCustomers()} — the global
 * SoftDeletes scope handles trashed rows, and the `is_active` filter
 * is applied on top so inactive products never leak
 * (docs/architecture/catalog.md § 1.4).
 *
 * @property int $id
 * @property int $category_id
 * @property string $code
 * @property array<string, string>|string $name
 * @property array<string, string>|string|null $short_description
 * @property array<string, string>|string|null $description
 * @property array<string, array<int, string>>|array<int, string>|null $benefits
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $is_quote_only
 * @property StockStatus $stock_status
 * @property int $display_order
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Category|null $category
 *
 * @method static Builder<static> visibleToCustomers()
 * @method static Builder<static> featured()
 * @method static Builder<static> inCategory(int $categoryId)
 */
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasTranslations;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'products';

    /** @var list<string> */
    protected $fillable = [
        'category_id',
        'code',
        'name',
        'short_description',
        'description',
        'benefits',
        'is_active',
        'is_featured',
        'is_quote_only',
        'individual_buy_enabled',
        'discount_percentage',
        'original_price',
        'tag_label',
        'rent_stars',
        'is_machine_eligible',
        'stock_status',
        'display_order',
    ];

    /** @var array<int, string> */
    public array $translatable = ['name', 'short_description', 'description', 'benefits'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_quote_only' => 'boolean',
            'individual_buy_enabled' => 'boolean',
            'discount_percentage' => 'decimal:2',
            'original_price' => 'decimal:2',
            'rent_stars' => 'integer',
            'is_machine_eligible' => 'boolean',
            'stock_status' => StockStatus::class,
            'display_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'category_id',
                'code',
                'name',
                'short_description',
                'description',
                'benefits',
                'is_active',
                'is_featured',
                'is_quote_only',
                'discount_percentage',
                'original_price',
                'tag_label',
                'rent_stars',
                'stock_status',
                'display_order',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('product');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Order line items that reference this product. Used by the
     * admin-side "block delete when product has active orders" guard
     * (Phase 16) and `orders_count` admin index column.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderByDesc('is_primary')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<ProductSpec, $this>
     */
    public function specs(): HasMany
    {
        return $this->hasMany(ProductSpec::class)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    /**
     * All price rows (active + inactive) — admin views only.
     *
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Only the active price rows — the customer-facing relation.
     *
     * @return HasMany<ProductPrice, $this>
     */
    public function activePrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class)
            ->where('is_active', true)
            ->orderBy('currency')
            ->orderBy('term')
            ->orderBy('amount');
    }

    /**
     * Customer visibility scope — restricts to active rows.
     *
     * The SoftDeletes global scope already filters trashed rows; this
     * scope adds the `is_active = true` requirement so the same query
     * path serves list AND detail endpoints (docs/architecture/catalog.md
     * § 6.4).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToCustomers(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Convenience alias.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Restrict to featured rows (homepage hero carousels).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * Restrict to a specific category id.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
