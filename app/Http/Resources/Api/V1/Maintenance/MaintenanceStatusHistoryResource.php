<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Models\MaintenanceStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-visible row of the maintenance request status timeline.
 *
 * `actor_id` is anonymised down to `{ first_name }` only via
 * {@see MaintenanceActorResource}. Per
 * docs/architecture/machines-maintenance.md § 2.4.
 *
 * @property-read MaintenanceStatusHistory $resource
 */
final class MaintenanceStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MaintenanceStatusHistory $entry */
        $entry = $this->resource;

        $actor = null;
        if ($entry->relationLoaded('actor') && $entry->actor !== null) {
            $actor = (new MaintenanceActorResource($entry->actor))->resolve($request);
        }

        return [
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'actor_type' => $entry->actor_type->value,
            'actor' => $actor,
            'note' => $entry->note,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
