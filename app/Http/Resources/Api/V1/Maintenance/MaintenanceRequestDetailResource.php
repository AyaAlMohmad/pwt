<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;

/**
 * DETAIL shape of a {@see MaintenanceRequest}.
 *
 * Replaces `description_excerpt` with the full `description`, embeds
 * anonymised `assigned_to`, full `media[]` (signed URLs), full
 * `status_history[]`, and conditionally surfaces `resolution_notes`
 * (only when status is completed) / `cancellation_reason` (only when
 * status is cancelled).
 *
 * See docs/architecture/machines-maintenance.md § 4.4.
 *
 * @property-read MaintenanceRequest $resource
 */
final class MaintenanceRequestDetailResource extends MaintenanceRequestResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MaintenanceRequest $r */
        $r = $this->resource;

        $base = parent::toArray($request);

        unset($base['description_excerpt']);
        $base['description'] = $r->description;

        $base['assigned_to'] = $r->relationLoaded('assignedTo') && $r->assignedTo !== null
            ? (new MaintenanceActorResource($r->assignedTo))->resolve($request)
            : null;

        $base['machine'] = $this->resolveMachineFull($r);

        $base['media'] = $r->relationLoaded('media')
            ? MaintenanceRequestMediaResource::collection($r->media)->resolve($request)
            : [];

        $base['status_history'] = $r->relationLoaded('statusHistory')
            ? MaintenanceStatusHistoryResource::collection($r->statusHistory)->resolve($request)
            : [];

        // Conditional surfaces — never leak resolution before completed.
        if ($r->status === MaintenanceStatus::Completed) {
            $base['resolution_notes'] = $r->resolution_notes;
        }
        if ($r->status === MaintenanceStatus::Cancelled) {
            $base['cancellation_reason'] = $r->cancellation_reason;
        }

        $base['closed_at'] = $r->closed_at?->toIso8601String();
        $base['updated_at'] = $r->updated_at->toIso8601String();

        return $base;
    }

    /**
     * Full machine embed for the detail shape (adds `location_label`).
     *
     * @return array<string, mixed>|null
     */
    private function resolveMachineFull(MaintenanceRequest $r): ?array
    {
        if (! $r->relationLoaded('machine') || $r->machine === null) {
            return null;
        }

        $machine = $r->machine;
        $product = null;
        if ($machine->relationLoaded('product') && $machine->product !== null) {
            $locale = app()->getLocale();
            $product = [
                'code' => $machine->product->code,
                'name' => $machine->product->getTranslation('name', $locale),
            ];
        }

        return [
            'id' => $machine->id,
            'public_id' => $machine->public_id,
            'display_name' => $machine->displayName(),
            'serial_number' => $machine->serial_number,
            'installed_at' => $machine->installed_at?->toDateString(),
            'location_label' => $machine->location_label,
            'product' => $product,
        ];
    }
}
