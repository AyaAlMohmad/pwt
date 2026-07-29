<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Support;

use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape of a single {@see SupportTicketStatusHistory} row.
 *
 * Exposes the FULL actor identity (`id`, `name`, `role`) — vs the
 * customer-side resource which exposes only `actor_type`.
 *
 * See docs/architecture/admin-support.md § 4.4.
 *
 * @property-read SupportTicketStatusHistory $resource
 */
final class AdminSupportTicketStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);

        /** @var SupportTicketStatusHistory $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'actor_type' => $entry->actor_type,
            'actor' => $this->resolveActor($entry),
            'note' => $entry->note,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveActor(SupportTicketStatusHistory $entry): ?array
    {
        if ($entry->actor_type === 'system') {
            return [
                'id' => null,
                'name' => (string) __('support.system_sender'),
                'role' => 'system',
            ];
        }

        if (! $entry->relationLoaded('actor') || $entry->actor === null) {
            return null;
        }

        return $this->actorSummary($entry->actor);
    }

    /**
     * @return array<string, mixed>
     */
    private function actorSummary(User $user): array
    {
        $role = null;
        if ($user->relationLoaded('roles')) {
            $names = $user->getRoleNames()->all();
            $role = $names === [] ? null : (string) $names[0];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $role,
        ];
    }
}
