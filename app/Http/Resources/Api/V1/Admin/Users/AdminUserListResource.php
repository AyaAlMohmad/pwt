<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Users;

use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single user row shape used by GET /api/v1/admin/users index responses.
 *
 * Includes `counts` (orders / support tickets / machines) so the
 * dashboard can render workload badges without per-row lookups. Counts
 * arrive on the model via `withCount(...)`.
 *
 * Role resolution reuses {@see AdminAuthService::resolveAdminRole()};
 * customers fall back to the literal string `customer`.
 *
 * @property-read User $resource
 */
class AdminUserListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $phoneE164 = $user->phone !== null
            ? $user->phone_country_code.$user->phone
            : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_country_code' => $user->phone_country_code,
            'phone_e164' => $phoneE164,
            'locale' => $user->locale,
            'is_active' => (bool) $user->is_active,
            'type' => $user->account_type?->value ?? null,
            'emirate' => $this->resolveEmirate($user),
            'role' => $this->resolveRole($user),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'deleted_at' => $user->deleted_at?->toIso8601String(),
            'counts' => (new AdminUserCountsResource($user))->resolve($request),
        ];
    }

    /**
     * Resolve the user's single role name. Prefers an admin role; falls
     * back to the literal `customer` when only the customer role is
     * held; returns null when no role is attached (defensive — every
     * seeded user has at least one role).
     */
    private function resolveRole(User $user): ?string
    {
        $adminRole = app(AdminAuthService::class)->resolveAdminRole($user);
        if ($adminRole !== null) {
            return $adminRole;
        }

        $names = $user->getRoleNames()->all();
        if (in_array('customer', $names, true)) {
            return 'customer';
        }

        return $names === [] ? null : (string) $names[0];
    }

    /**
     * Resolve the user's emirate from their default address region.
     */
    private function resolveEmirate(User $user): ?string
    {
        if (! $user->relationLoaded('addresses')) {
            return null;
        }

        $default = $user->addresses->first(static fn ($a) => (bool) $a->is_default);
        if ($default === null) {
            $default = $user->addresses->first();
        }

        if ($default === null) {
            return null;
        }

        $region = $default->relationLoaded('region') ? $default->region : null;
        if ($region !== null) {
            return $region->getTranslation('name', app()->getLocale(), false)
                ?: $region->getTranslation('name', (string) config('app.fallback_locale', 'en'));
        }

        return $default->city ?: null;
    }
}
