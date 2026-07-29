<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Shape a Sanctum `NewAccessToken` for the API response.
 *
 * The plain-text value is shown ONCE at issuance; further reads of the
 * token row only expose the hashed form.
 *
 * @property-read NewAccessToken $resource
 */
final class AuthTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var NewAccessToken $newToken */
        $newToken = $this->resource;

        /** @var array<int, string> $abilities */
        $abilities = (array) $newToken->accessToken->abilities;

        return [
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'abilities' => array_values($abilities),
        ];
    }
}
