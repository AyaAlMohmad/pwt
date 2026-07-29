<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

/**
 * Promo-code definition.
 *
 * Looked up by `code` (uppercase + trimmed — the service layer applies
 * the normalisation). `applies_to` is one of `all`, `products`,
 * `categories`; the latter two restrict applicability via the
 * `promotion_products` / `promotion_categories` pivots.
 *
 * Per docs/architecture/promotions.md § 1.5, the model is read-only from
 * the customer surface — Phase 8 writes the redemption row inside the
 * order-creation transaction.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string>|string $name
 * @property array<string, string>|string|null $description
 * @property DiscountType $type
 * @property string $value
 * @property string|null $max_discount_amount
 * @property string|null $currency
 * @property string|null $min_order_amount
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $per_user_limit
 * @property int|null $total_limit
 * @property PromoScope $applies_to
 * @property bool $is_active
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> active()
 * @method static Builder<static> applicableTo(int $productId)
 * @method static Builder<static> applicableToCategory(int $categoryId)
 */
final class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    use HasTranslations;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'promotions';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'value',
        'max_discount_amount',
        'currency',
        'min_order_amount',
        'starts_at',
        'ends_at',
        'per_user_limit',
        'total_limit',
        'applies_to',
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
            'type' => DiscountType::class,
            'applies_to' => PromoScope::class,
            'value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'per_user_limit' => 'integer',
            'total_limit' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'code',
                'name',
                'description',
                'type',
                'value',
                'max_discount_amount',
                'currency',
                'min_order_amount',
                'starts_at',
                'ends_at',
                'per_user_limit',
                'total_limit',
                'applies_to',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('promotion');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promotion_categories')
            ->withTimestamps();
    }

    /**
     * @return HasMany<PromotionRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * Currently-active promotions: `is_active=true` AND inside the
     * `starts_at`/`ends_at` window. Null window bounds are treated as
     * "no bound" (an admin convenience used by the seeded `WELCOME10`
     * style promotions which have no end date).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(static function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(static function (Builder $q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    /**
     * Restrict to promotions that apply to a particular product id —
     * either by virtue of `applies_to = 'all'` or by an explicit
     * `promotion_products` pivot row.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApplicableTo(Builder $query, int $productId): Builder
    {
        return $query->where(static function (Builder $q) use ($productId): void {
            $q->where('applies_to', PromoScope::All->value)
                ->orWhere(static function (Builder $q2) use ($productId): void {
                    $q2->where('applies_to', PromoScope::Products->value)
                        ->whereExists(static function ($sub) use ($productId): void {
                            $sub->select(DB::raw(1))
                                ->from('promotion_products')
                                ->whereColumn('promotion_products.promotion_id', 'promotions.id')
                                ->where('promotion_products.product_id', $productId);
                        });
                });
        });
    }

    /**
     * Restrict to promotions that apply to a particular category id.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApplicableToCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where(static function (Builder $q) use ($categoryId): void {
            $q->where('applies_to', PromoScope::All->value)
                ->orWhere(static function (Builder $q2) use ($categoryId): void {
                    $q2->where('applies_to', PromoScope::Categories->value)
                        ->whereExists(static function ($sub) use ($categoryId): void {
                            $sub->select(DB::raw(1))
                                ->from('promotion_categories')
                                ->whereColumn('promotion_categories.promotion_id', 'promotions.id')
                                ->where('promotion_categories.category_id', $categoryId);
                        });
                });
        });
    }

    protected static function newFactory(): PromotionFactory
    {
        return PromotionFactory::new();
    }
}
