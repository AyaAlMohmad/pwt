<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Resources\Api\V1\Catalog\ProductResource;
use App\Models\Product;

/**
 * Customer-facing inventory availability for a catalog {@see Product}.
 *
 * The value is stored as a `string(20)` column (never MySQL `ENUM` per
 * CLAUDE.md § 6) and round-trips through Spatie/Laravel casts to this
 * backed enum. The bilingual label map is consumed by the
 * {@see ProductResource} when the
 * customer UI wants to render the badge without a client-side dictionary.
 */
enum StockStatus: string
{
    case InStock = 'in_stock';
    case OutOfStock = 'out_of_stock';
    case ComingSoon = 'coming_soon';
    case Discontinued = 'discontinued';

    /**
     * Bilingual label for UI badges.
     *
     * @return array{en: string, ar: string}
     */
    public function label(): array
    {
        return match ($this) {
            self::InStock => ['en' => 'In stock', 'ar' => 'متوفر'],
            self::OutOfStock => ['en' => 'Out of stock', 'ar' => 'غير متوفر'],
            self::ComingSoon => ['en' => 'Coming soon', 'ar' => 'قريباً'],
            self::Discontinued => ['en' => 'Discontinued', 'ar' => 'متوقف'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
