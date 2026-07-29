<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ProductPrice;

/**
 * The commercial term that a {@see ProductPrice} row applies to.
 *
 * Per BRD p. 7 (Service 5 — Pricing & Plans):
 *  - `buy`  → one-off purchase (no `period`, no `min_term_months`)
 *  - `rent` → recurring rental  (`period = monthly|yearly`, optional min commit)
 *  - `foc`  → "free on contract" (`amount = 0.00`, contract-bound)
 */
enum ProductTerm: string
{
    case Buy = 'buy';
    case Rent = 'rent';
    case Foc = 'foc';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
