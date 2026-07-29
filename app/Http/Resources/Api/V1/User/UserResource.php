<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer profile shape used in /auth and /me responses.
 *
 * @property-read User $resource
 */
final class UserResource extends JsonResource
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

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $phone,
            'phone_country_code' => $user->phone_country_code,
            'locale' => $user->locale,
            'is_active' => (bool) $user->is_active,
            'account_type' => $user->account_type,
            'company_name' => $user->company_name,
            'avatar_url' => $user->avatar?->publicUrl(),
            'roles' => $user->getRoleNames()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
