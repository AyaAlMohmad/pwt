<?php

declare(strict_types=1);

namespace App\Exceptions\Admin\Promotions;

use App\Exceptions\AppException;

/**
 * Raised when an admin tries to soft-delete a promotion that has at least
 * one immutable redemption row recorded against it.
 *
 * Mapped to HTTP 422 `ERR_PROMOTION_HAS_REDEMPTIONS` by the global handler.
 *
 * Admins' escape hatch is to set `is_active=false` via PUT, which is
 * non-destructive and preserves historical reporting joins.
 *
 * See docs/architecture/admin-content.md § 5.5 / § 9.1.
 */
final class PromotionHasRedemptionsException extends AppException
{
    public static function create(int $redemptionsCount): self
    {
        return new self(
            __('admin.promotions.has_redemptions'),
            422,
            'ERR_PROMOTION_HAS_REDEMPTIONS',
            ['redemptions_count' => [(string) $redemptionsCount]],
        );
    }
}
