<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Support;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Customer-facing LIST shape of a {@see SupportTicket}.
 *
 * `assigned_to_first_name` is anonymised to the assignee's first name
 * only (or null when unassigned). `last_message_at` is the timestamp
 * of the latest non-internal-note message; computed via subquery in
 * `SupportTicketService::listForUser()`. `unread_message_count` ships
 * as `0` in Phase 12 — Phase 19 will wire the real value.
 *
 * Hidden from list output (allowlist enforcement):
 *   - user_id (always equals caller)
 *   - assigned_to_user_id (replaced by anonymised name)
 *   - resolution_summary (detail-only)
 *   - cancellation_reason / cancelled_at / cancelled_by_user_id
 *     (surfaced via status_history in detail only)
 *   - related_order_id / related_machine_id (replaced by hydrated
 *     summaries in detail)
 *
 * @property-read SupportTicket $resource
 */
class SupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicket $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'public_id' => $t->public_id,
            'subject' => $t->subject,
            'category' => $t->category->value,
            'priority' => $t->priority->value,
            'status' => $t->status->value,
            'assigned_to_first_name' => $this->resolveAssigneeFirstName($t),
            'opened_at' => $t->opened_at->toIso8601String(),
            'first_response_at' => $t->first_response_at?->toIso8601String(),
            'closed_at' => $t->closed_at?->toIso8601String(),
            'last_message_at' => $this->resolveLastMessageAt($t),
            'unread_message_count' => $this->resolveUnreadCount($t),
            'is_cancellable' => $t->status->isCustomerCancellable(),
            'created_at' => $t->created_at->toIso8601String(),
        ];
    }

    /**
     * Pull the persisted `last_message_at` column (Phase 19) or fall
     * back to the legacy subquery alias for tickets that pre-date the
     * backfill. Falls back to `created_at` as a last resort so the
     * inbox sort never returns null.
     */
    protected function resolveLastMessageAt(SupportTicket $t): ?string
    {
        $persisted = $t->last_message_at;
        if ($persisted !== null) {
            return $persisted->toIso8601String();
        }

        $raw = $t->getAttribute('last_message_subquery');
        if (is_string($raw) && $raw !== '') {
            return Carbon::parse($raw)->toIso8601String();
        }

        return $t->created_at->toIso8601String();
    }

    /**
     * Prefer the eager-loaded subquery alias (set by
     * `SupportTicketService::listForUser()`) — single round-trip
     * across the whole page. Falls back to the model accessor for
     * single-detail loads.
     */
    protected function resolveUnreadCount(SupportTicket $t): int
    {
        $raw = $t->getAttributes()['unread_message_count'] ?? null;
        if (is_numeric($raw)) {
            return (int) $raw;
        }

        return $t->unread_message_count;
    }

    /**
     * First-name anonymisation. Falls back to a localised "Support"
     * marker when the user name is empty (defensive against bad data).
     */
    protected function resolveAssigneeFirstName(SupportTicket $t): ?string
    {
        if (! $t->relationLoaded('assignedTo') || $t->assignedTo === null) {
            return null;
        }

        $name = trim((string) $t->assignedTo->name);
        if ($name === '') {
            return (string) __('support.support_fallback_name');
        }

        $parts = preg_split('/\s+/', $name);
        if (! is_array($parts)) {
            return $name;
        }

        return (string) $parts[0];
    }
}
