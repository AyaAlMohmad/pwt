<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Promotion;

/**
 * Scope of products a {@see Promotion} applies to.
 *
 * - `all`        — every product / every category.
 * - `products`   — restricted to the rows in `promotion_products`.
 * - `categories` — restricted to the rows in `promotion_categories`.
 *
 * See docs/architecture/promotions.md § 8.2.
 */
enum PromoScope: string
{
    case All = 'all';
    case Products = 'products';
    case Categories = 'categories';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
