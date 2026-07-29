<?php

declare(strict_types=1);

namespace App\Exceptions\Addresses;

use App\Exceptions\AppException;
use App\Services\Addresses\AddressService;

/**
 * Raised when a DELETE /me/addresses/{id} call targets an address that
 * is still referenced by an active order.
 *
 * Mapped to HTTP 409 with `code = ERR_ADDRESS_IN_USE`.
 *
 * Phase 5 ships with a stubbed precondition check (the controller calls
 * {@see AddressService::isAddressInUse()} which
 * always returns false); Phase 8 (Orders) will swap the stub for a real
 * query on the orders table.
 */
final class AddressInUseException extends AppException
{
    public static function create(): self
    {
        return new self(
            __('addresses.in_use'),
            409,
            'ERR_ADDRESS_IN_USE',
        );
    }
}
