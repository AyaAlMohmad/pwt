<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Users;

use App\Http\Resources\Api\V1\Addresses\AddressResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Single-user detail payload for GET /api/v1/admin/users/{user},
 * PATCH /api/v1/admin/users/{user}, and DELETE /api/v1/admin/users/{user}.
 *
 * Extends {@see AdminUserListResource} with an embedded address book
 * so that create / detail responses return the full user profile.
 *
 * @property-read User $resource
 */
final class AdminUserDetailResource extends AdminUserListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $data = parent::toArray($request);

        if ($user->relationLoaded('addresses')) {
            $data['addresses'] = AddressResource::collection($user->addresses);
        } else {
            $data['addresses'] = [];
        }

        return $data;
    }
}
