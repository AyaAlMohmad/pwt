<?php

declare(strict_types=1);

namespace App\Exceptions\Admin\Users;

use App\Exceptions\AppException;

/**
 * Raised when an admin attempts to deactivate (`is_active=false`) or
 * soft-delete their own account.
 *
 * Mapped to HTTP 422 `ERR_CANNOT_DEACTIVATE_SELF` by the global handler.
 *
 * See docs/architecture/admin-users.md § 5.5.
 */
final class CannotDeactivateSelfException extends AppException
{
    public static function create(): self
    {
        return new self(
            __('admin.users.cannot_deactivate_self'),
            422,
            'ERR_CANNOT_DEACTIVATE_SELF',
        );
    }
}
