<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Users;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Aggregate counts for a single user — orders, support tickets, and
 * machines.
 *
 * The counts come from `withCount(['orders', 'supportTickets',
 * 'machines'])` (list path) or `loadCount([...])` (detail path) so the
 * resource never triggers per-row relation queries. SoftDeletes global
 * scopes on the related models exclude trashed rows from the counts —
 * see docs/architecture/admin-users.md § 9.6.
 *
 * @property-read User $resource
 */
final class AdminUserCountsResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'orders_count' => $this->resolveCount($user, 'orders_count'),
            'support_tickets_count' => $this->resolveCount($user, 'support_tickets_count'),
            'machines_count' => $this->resolveCount($user, 'machines_count'),
        ];
    }

    private function resolveCount(User $user, string $attribute): int
    {
        $value = $user->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }
}
