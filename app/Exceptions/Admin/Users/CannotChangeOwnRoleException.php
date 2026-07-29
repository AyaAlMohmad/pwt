<?php

declare(strict_types=1);

namespace App\Exceptions\Admin\Users;

use App\Exceptions\AppException;

/**
 * Raised when an admin attempts to change their own role through the
 * admin-users update endpoint.
 *
 * Mapped to HTTP 422 `ERR_CANNOT_CHANGE_OWN_ROLE` by the global handler.
 *
 * See docs/architecture/admin-users.md § 5.5.
 */
final class CannotChangeOwnRoleException extends AppException
{
    public static function create(): self
    {
        return new self(
            __('admin.users.cannot_change_own_role'),
            422,
            'ERR_CANNOT_CHANGE_OWN_ROLE',
        );
    }
}
