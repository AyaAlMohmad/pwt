<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use App\Http\Resources\Api\V1\User\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Composed response wrapping a user + access token, used by both
 * /auth/login (status 200) and /auth/register (status 201).
 *
 * @property-read array{user: User, token: NewAccessToken} $resource
 */
final class LoginResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{user: User, token: NewAccessToken} $payload */
        $payload = $this->resource;

        return [
            'user' => (new UserResource($payload['user']))->resolve($request),
            'token' => (new AuthTokenResource($payload['token']))->resolve($request),
        ];
    }
}
