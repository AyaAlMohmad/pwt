<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Middleware\AdminGuard;

/**
 * Application user roles.
 *
 * `Customer` is the public-facing role; the remaining roles all belong to
 * the admin panel and are gated by the `admin` middleware (see
 * {@see AdminGuard}).
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Operations = 'operations';
    case Sales = 'sales';
    case Support = 'support';
    case Customer = 'customer';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function adminValues(): array
    {
        return [
            self::SuperAdmin->value,
            self::Operations->value,
            self::Sales->value,
            self::Support->value,
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->value, self::adminValues(), true);
    }
}
