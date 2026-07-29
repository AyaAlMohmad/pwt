<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Orders;

use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-visible row of the order status timeline.
 *
 * `actor_id` is intentionally hidden — the customer needs to know the
 * actor type (customer / admin / system) but not which admin user
 * fired the transition.
 *
 * @property-read OrderStatusHistory $resource
 */
final class OrderStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderStatusHistory $entry */
        $entry = $this->resource;

        return [
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'actor_type' => $entry->actor_type->value,
            'note' => $entry->note,
            'occurred_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
