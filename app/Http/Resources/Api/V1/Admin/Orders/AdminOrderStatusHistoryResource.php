<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Orders;

use App\Http\Resources\Api\V1\Orders\OrderStatusHistoryResource;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-side row of the order status timeline.
 *
 * Distinct from {@see OrderStatusHistoryResource}:
 *  - Exposes `actor_id` + `actor_name` (resolved from the eager-loaded
 *    `actor` relation) so the audit trail is complete.
 *  - Surfaces `is_internal` + the convenience `is_notes_only` flag
 *    (true when `from_status === to_status`).
 *
 * @property-read OrderStatusHistory $resource
 */
final class AdminOrderStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var OrderStatusHistory $entry */
        $entry = $this->resource;

        $actor = $entry->relationLoaded('actor') ? $entry->actor : null;

        return [
            'id' => $entry->id,
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'actor_type' => $entry->actor_type->value,
            'actor_id' => $entry->actor_id,
            'actor_name' => $actor?->name,
            'note' => $entry->note,
            'is_internal' => (bool) $entry->is_internal,
            'is_notes_only' => $entry->from_status === $entry->to_status,
            'occurred_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
