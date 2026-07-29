<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductTerm;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * One line of a customer {@see Order}.
 *
 * Snapshot columns capture the product context at order time so later
 * product renames / price changes do not mutate historical orders.
 * `product_name_snapshot` is a translatable JSON column.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $product_code
 * @property array<string, string>|string $product_name_snapshot
 * @property ProductTerm $term
 * @property string $unit_price
 * @property int $quantity
 * @property string $currency
 * @property string $line_subtotal
 * @property string $line_discount
 * @property string $line_tax
 * @property string $line_total
 * @property string|null $rent_period
 * @property int|null $rent_min_term_months
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order|null $order
 * @property-read Product|null $product
 */
final class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    use HasTranslations;

    protected $table = 'order_items';

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_code',
        'product_name_snapshot',
        'term',
        'unit_price',
        'quantity',
        'currency',
        'line_subtotal',
        'line_discount',
        'line_tax',
        'line_total',
        'rent_period',
        'rent_min_term_months',
    ];

    /** @var array<int, string> */
    public array $translatable = ['product_name_snapshot'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term' => ProductTerm::class,
            'unit_price' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_discount' => 'decimal:2',
            'line_tax' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'rent_min_term_months' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }
}
