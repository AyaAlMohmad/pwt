<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Customer-selectable payment method at checkout.
 *
 * COD ("cash on delivery") remains `pending` until an admin manually
 * confirms; online methods auto-confirm via the Phase 9 payment webhook.
 * See docs/architecture/orders.md § 9.5.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case ApplePay = 'apple_pay';
    case GooglePay = 'google_pay';
    case Cod = 'cod';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Online payment methods auto-confirm via the gateway webhook.
     * COD stays pending until manual admin confirmation.
     */
    public function isOnline(): bool
    {
        return $this !== self::Cod;
    }
}
