<?php

declare(strict_types=1);

namespace App\Exceptions\Addresses;

use App\Exceptions\AppException;

/**
 * Raised when a customer attempts to create an 11th (or beyond) address.
 *
 * Mapped to HTTP 422 with `code = ERR_ADDRESS_LIMIT_REACHED` by the
 * global exception handler (see bootstrap/app.php).
 *
 * Limit is configurable via `config('addresses.max_per_user')`.
 */
final class AddressLimitReachedException extends AppException
{
    public static function create(int $max): self
    {
        return new self(
            __('addresses.limit_reached', ['max' => $max]),
            422,
            'ERR_ADDRESS_LIMIT_REACHED',
        );
    }
}
