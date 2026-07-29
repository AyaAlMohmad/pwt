<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Auth;

use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape the response for GET /api/v1/admin/me.
 *
 * Same composition as the login success payload but with the `token`
 * block stripped — the caller already holds a valid token in their
 * Authorization header.
 *
 * @property-read User $resource
 */
final class AdminMeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $service = app(AdminAuthService::class);

        return [
            'user' => (new AdminUserResource($user))->resolve($request),
            'role' => $service->resolveAdminRole($user),
            'permissions' => $service->permissionKeys($user),
            'capabilities' => $service->buildCapabilities($user),
        ];
    }
}
