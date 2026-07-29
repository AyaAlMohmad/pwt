<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductTerm;
use Database\Factories\ProductPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One price row for a {@see Product} keyed on `(term, currency)`.
 *
 * Money is stored as `decimal(12,2)` per CLAUDE.md § 6 — never `float`.
 * Inactive rows are admin-archived prices (e.g. retired promotional
 * pricing) and are filtered out of customer responses.
 *
 * Phase 8 (Orders) snapshots the row's `amount`, `currency`, and `term`
 * into the corresponding `order_items` columns at checkout time so a
 * later price change cannot mutate historical orders.
 *
 * @property int $id
 * @property int $product_id
 * @property ProductTerm $term
 * @property string $amount
 * @property string $currency
 * @property string|null $period
 * @property int|null $min_term_months
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product|null $product
 *
 * @method static Builder<static> active()
 * @method static Builder<static> forTerm(ProductTerm $term)
 */
final class ProductPrice extends Model
{
    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory;

    protected $table = 'product_prices';

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'term',
        'amount',
        'currency',
        'period',
        'min_term_months',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term' => ProductTerm::class,
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'min_term_months' => 'integer',
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTerm(Builder $query, ProductTerm $term): Builder
    {
        return $query->where('term', $term->value);
    }

    protected static function newFactory(): ProductPriceFactory
    {
        return ProductPriceFactory::new();
    }
}
