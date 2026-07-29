<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Auth;

use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape an admin User row for the /admin/auth/* responses.
 *
 * `role` is a single string (not an array). The successful path of
 * the admin auth flow already verifies the user is active, so
 * `is_active` is intentionally omitted — there is no point exposing
 * "you are active" to an authenticated admin.
 *
 * @property-read User $resource
 */
final class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $phone = $user->phone !== null
            ? $user->phone_country_code.$user->phone
            : null;

        $role = app(AdminAuthService::class)->resolveAdminRole($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $phone,
            'phone_country_code' => $user->phone_country_code,
            'role' => $role,
            'locale' => $user->locale,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
