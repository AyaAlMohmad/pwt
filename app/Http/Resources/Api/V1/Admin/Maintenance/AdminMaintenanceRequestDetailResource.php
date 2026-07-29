<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Maintenance;

use App\Http\Resources\Api\V1\Maintenance\MaintenanceRequestMediaResource;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceStatusHistory;
use Illuminate\Http\Request;

/**
 * Full single-request shape returned by GET /api/v1/admin/maintenance-
 * requests/{id} and admin write endpoints.
 *
 * Adds (vs list shape):
 *  - Full `description` (no excerpt).
 *  - `internal_notes` (admin-only).
 *  - `resolution_notes`, `cancellation_reason`, `cancelled_by`.
 *  - `media[]` with signed URLs.
 *  - `status_history[]` with full actor identity (id + name + role).
 *  - `machine` extended with location_label, address, is_active.
 *
 * @property-read MaintenanceRequest $resource
 */
final class AdminMaintenanceRequestDetailResource extends AdminMaintenanceRequestResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MaintenanceRequest $req */
        $req = $this->resource;
        $base = parent::toArray($request);

        $base['description'] = $req->description;
        $base['internal_notes'] = $req->internal_notes;
        $base['resolution_notes'] = $req->resolution_notes;
        $base['cancellation_reason'] = $req->cancellation_reason;
        $base['cancelled_by'] = $this->resolveCancelledBy($req);

        $base['machine'] = $this->resolveMachineDetail($req);

        $base['media'] = $req->relationLoaded('media')
            ? MaintenanceRequestMediaResource::collection($req->media)->resolve($request)
            : [];

        $base['status_history'] = $req->relationLoaded('statusHistory')
            ? $req->statusHistory->map(fn (MaintenanceStatusHistory $h) => $this->resolveHistory($h))->values()->all()
            : [];

        $base['deleted_at'] = $req->deleted_at?->toIso8601String();

        return $base;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCancelledBy(MaintenanceRequest $req): ?array
    {
        if (! $req->relationLoaded('cancelledBy') || $req->cancelledBy === null) {
            return null;
        }

        return [
            'id' => $req->cancelledBy->id,
            'name' => $req->cancelledBy->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMachineDetail(MaintenanceRequest $req): ?array
    {
        if (! $req->relationLoaded('machine') || $req->machine === null) {
            return null;
        }

        $machine = $req->machine;
        $product = $machine->relationLoaded('product') ? $machine->product : null;
        $address = $machine->relationLoaded('address') ? $machine->address : null;
        $country = $address !== null && $address->relationLoaded('country') ? $address->country : null;

        return [
            'id' => $machine->id,
            'public_id' => $machine->public_id,
            'display_name' => $machine->displayName(),
            'serial_number' => $machine->serial_number,
            'installed_at' => $machine->installed_at?->toDateString(),
            'location_label' => $machine->location_label,
            'is_active' => (bool) $machine->is_active,
            'product' => $product !== null
                ? ['id' => $product->id, 'code' => $product->code]
                : null,
            'address' => $address !== null
                ? [
                    'id' => $address->id,
                    'label' => $address->label,
                    'city' => $address->city,
                    'country' => $country !== null ? ['code' => $country->code] : null,
                ]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveHistory(MaintenanceStatusHistory $history): array
    {
        $actor = $history->relationLoaded('actor') ? $history->actor : null;
        $actorSummary = $actor !== null
            ? $this->resolveActorSummary($actor)
            : null;

        return [
            'id' => $history->id,
            'from_status' => $history->from_status,
            'to_status' => $history->to_status,
            'actor_type' => $history->actor_type->value,
            'actor' => $actorSummary,
            'note' => $history->note,
            'occurred_at' => $history->created_at->toIso8601String(),
        ];
    }
}
