<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Customer account type — individual (personal) or company (business).
 */
enum AccountType: string
{
    case Individual = 'individual';
    case Company = 'company';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
