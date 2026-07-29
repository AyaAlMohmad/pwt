<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin wrapper around UserResource for /me responses — kept as a
 * distinct class so the OpenAPI schema for MeResponse can evolve
 * independently (e.g. adding `pending_phone_change` later).
 *
 * @property-read User $resource
 */
final class MeResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return (new UserResource($user))->resolve($request);
    }
}
