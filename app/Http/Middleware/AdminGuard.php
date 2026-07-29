<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject any authenticated request whose user does not hold an
 * admin role OR has been deactivated.
 *
 * Authentication is verified first (returns 401), then role
 * membership AND the `is_active` flag are checked together (returns
 * 403). Both failures use the standard PWT error envelope.
 *
 * Phase 14 — extended to reject `is_active === false` so a disabled
 * admin's unrevoked Sanctum token cannot continue to access admin
 * endpoints. The inactive case is surfaced with a dedicated
 * `ERR_ADMIN_INACTIVE` code so the dashboard can present a tailored
 * "your account has been disabled" screen instead of the generic
 * forbidden message. See docs/architecture/admin-auth.md § 6.5.
 */
final class AdminGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error(
                __('messages.unauthenticated'),
                Response::HTTP_UNAUTHORIZED,
                code: 'ERR_UNAUTHENTICATED',
            );
        }

        if (! $user->hasAnyRole(UserRole::adminValues())) {
            return ApiResponse::error(
                __('messages.forbidden'),
                Response::HTTP_FORBIDDEN,
                code: 'ERR_FORBIDDEN',
            );
        }

        if ($user->is_active !== true) {
            return ApiResponse::error(
                __('admin.inactive'),
                Response::HTTP_FORBIDDEN,
                code: 'ERR_ADMIN_INACTIVE',
            );
        }

        return $next($request);
    }
}
