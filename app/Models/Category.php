<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
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
 * Catalog category — top-level grouping for {@see Product}.
 *
 * Each category exposes a stable numeric `id` (used as the
 * `?category_id=` filter on `/api/v1/products`) plus a slug-style
 * `code` for SEO-friendly client routes. Customer endpoints scope every
 * query to {@see scopeVisibleToCustomers()} so inactive rows never leak
 * (see docs/architecture/catalog.md § 1.4).
 *
 * `parent_id` is reserved for future sub-category support (Phase 2) and
 * is NOT exposed in any customer response.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $code
 * @property array<string, string>|string $name
 * @property array<string, string>|string|null $description
 * @property string|null $image_url
 * @property int $display_order
 * @property bool $is_active
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> visibleToCustomers()
 */
final class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasTranslations;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'categories';

    /** @var list<string> */
    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'description',
        'image_url',
        'display_order',
        'is_active',
    ];

    /** @var array<int, string> */
    public array $translatable = ['name', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'parent_id',
                'code',
                'name',
                'description',
                'image_url',
                'display_order',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('category');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Restrict the query to rows visible to customer-facing endpoints.
     *
     * The customer must never see inactive categories — neither in the
     * list nor by ID lookup (docs/architecture/catalog.md § 1.4).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleToCustomers(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Convenience alias for code that prefers the generic "active" verb.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
