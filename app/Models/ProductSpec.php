<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductSpecFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * A `(label, value)` pair on a {@see Product}'s technical-spec table.
 *
 * Both columns are translatable JSON so admin can publish Arabic
 * numerals (e.g. `"٥"`) or Arabic units (e.g. `"٥ مراحل"`) for the AR
 * locale.
 *
 * @property int $id
 * @property int $product_id
 * @property array<string, string>|string $label
 * @property array<string, string>|string $value
 * @property int $display_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product|null $product
 */
final class ProductSpec extends Model
{
    /** @use HasFactory<ProductSpecFactory> */
    use HasFactory;

    use HasTranslations;

    protected $table = 'product_specs';

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'label',
        'value',
        'display_order',
    ];

    /** @var array<int, string> */
    public array $translatable = ['label', 'value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

    protected static function newFactory(): ProductSpecFactory
    {
        return ProductSpecFactory::new();
    }
}
