<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Support;

use App\Http\Resources\Api\V1\Support\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin LIST shape of a {@see SupportTicket}.
 *
 * Distinct from customer {@see SupportTicketResource}:
 *  - Embeds full customer PII (id/name/email/phone).
 *  - Surfaces `assigned_to.role` (full admin identity, not first-name anonymised).
 *  - Adds `related_order_id`, `related_machine_id` (raw ids).
 *  - Adds `message_count`, `internal_note_count` (subquery counts).
 *  - Adds `last_message_at`, `cancelled_at`, `is_trashed`.
 *  - Hides `resolution_summary`, `cancellation_reason` (detail-only).
 *
 * @property-read SupportTicket $resource
 */
class AdminSupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);

        /** @var SupportTicket $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'public_id' => $t->public_id,
            'subject' => $t->subject,
            'category' => $t->category->value,
            'status' => $t->status->value,
            'priority' => $t->priority->value,
            'customer' => $this->resolveCustomer($t),
            'assigned_to' => $this->resolveAssignedTo($t),
            'related_order_id' => $t->related_order_id,
            'related_machine_id' => $t->related_machine_id,
            'opened_at' => $t->opened_at->toIso8601String(),
            'first_response_at' => $t->first_response_at?->toIso8601String(),
            'last_message_at' => $t->last_message_at?->toIso8601String(),
            'closed_at' => $t->closed_at?->toIso8601String(),
            'cancelled_at' => $t->cancelled_at?->toIso8601String(),
            'message_count' => $this->resolveIntAttr($t, 'message_count'),
            'internal_note_count' => $this->resolveIntAttr($t, 'internal_note_count'),
            'is_trashed' => $t->trashed(),
            'created_at' => $t->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveCustomer(SupportTicket $t): ?array
    {
        if (! $t->relationLoaded('user') || $t->user === null) {
            return null;
        }

        $user = $t->user;
        $phoneE164 = $user->phone !== null
            ? $user->phone_country_code.$user->phone
            : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $phoneE164,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveAssignedTo(SupportTicket $t): ?array
    {
        if (! $t->relationLoaded('assignedTo') || $t->assignedTo === null) {
            return null;
        }

        return $this->resolveActorSummary($t->assignedTo);
    }

    /**
     * Full admin shape `{id, name, role}`. The role is the PRIMARY
     * (first) role on the user — supports our flat MVP role model
     * where each admin holds exactly one role.
     *
     * @return array<string, mixed>
     */
    protected function resolveActorSummary(User $user): array
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

    /**
     * Pull an int subquery alias (e.g. `message_count`) emitted by the
     * list query. Defaults to 0 on a single-load where the alias is
     * absent.
     */
    protected function resolveIntAttr(SupportTicket $t, string $key): int
    {
        $raw = $t->getAttributes()[$key] ?? null;
        if (is_numeric($raw)) {
            return (int) $raw;
        }

        return 0;
    }
}
