<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Support;

use App\Models\SupportTicketStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-visible row of the support ticket status timeline.
 *
 * `actor_user_id` is NOT exposed — only the `actor_type` ("customer" /
 * "admin" / "system") surfaces, mirroring `order_status_history`
 * discretion. The `note` field is shown verbatim (it carries the
 * cancellation reason for customer cancels and admin notes for admin
 * actions).
 *
 * See docs/architecture/support.md § 5.3.
 *
 * @property-read SupportTicketStatusHistory $resource
 */
final class SupportTicketStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicketStatusHistory $entry */
        $entry = $this->resource;

        return [
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'actor_type' => $entry->actor_type,
            'note' => $entry->note,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
