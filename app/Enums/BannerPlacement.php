<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Banner;

/**
 * Placement slot for a customer-facing {@see Banner}.
 *
 * Stored as a `string(20)` column (CLAUDE.md § 6 — no MySQL `ENUM`) and
 * round-tripped through Laravel's enum casting. The values map directly
 * to the four homepage banner slots (`hero`, `promo`, `sidebar`,
 * `footer`) per docs/architecture/promotions.md § 8.1.
 */
enum BannerPlacement: string
{
    case Hero = 'hero';
    case Promo = 'promo';
    case Sidebar = 'sidebar';
    case Footer = 'footer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
