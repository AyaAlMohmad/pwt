<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Promotion;

/**
 * Discount mechanism for a {@see Promotion}.
 *
 * - `percent` — `value` is interpreted as a percentage of the subtotal
 *   (0–100). Currency-agnostic.
 * - `fixed`   — `value` is a flat amount in the promotion's `currency`.
 *
 * See docs/architecture/promotions.md § 8.2.
 */
enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
