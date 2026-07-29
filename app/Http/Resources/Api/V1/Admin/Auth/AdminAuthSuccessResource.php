<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Auth;

use App\Http\Resources\Api\V1\Auth\AuthTokenResource;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Composed admin login response: user + token + role + permissions
 * + capability map.
 *
 * @property-read array{user: User, token: NewAccessToken} $resource
 */
final class AdminAuthSuccessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{user: User, token: NewAccessToken} $payload */
        $payload = $this->resource;
        $user = $payload['user'];

        $service = app(AdminAuthService::class);

        return [
            'user' => (new AdminUserResource($user))->resolve($request),
            'token' => (new AuthTokenResource($payload['token']))->resolve($request),
            'role' => $service->resolveAdminRole($user),
            'permissions' => $service->permissionKeys($user),
            'capabilities' => $service->buildCapabilities($user),
        ];
    }
}
