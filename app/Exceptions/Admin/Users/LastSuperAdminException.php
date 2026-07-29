<?php

declare(strict_types=1);

namespace App\Exceptions\Admin\Users;

use App\Exceptions\AppException;

/**
 * Raised when an attempted mutation (deactivate / soft-delete / role
 * change / is_active toggle) would leave the system with no active
 * super_admin.
 *
 * Mapped to HTTP 422 `ERR_LAST_SUPER_ADMIN` by the global handler.
 *
 * See docs/architecture/admin-users.md § 5.5 and § 9.9.
 */
final class LastSuperAdminException extends AppException
{
    public static function create(): self
    {
        return new self(
            __('admin.users.last_super_admin'),
            422,
            'ERR_LAST_SUPER_ADMIN',
        );
    }
}
