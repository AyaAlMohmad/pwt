<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Enums\MaintenanceLogStatus;
use App\Http\Resources\Api\V1\Addresses\AddressResource;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use Illuminate\Http\Request;

/**
 * Detail-shape of a {@see Machine} row — used by `GET /me/machines/{id}`.
 *
 * Extends {@see MachineResource} with `filter_change_interval_months`,
 * full `address` resource, latest 50 maintenance logs, and aggregate
 * counts.
 *
 * @property-read Machine $resource
 */
final class MachineDetailResource extends MachineResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Machine $machine */
        $machine = $this->resource;

        $base = parent::toArray($request);

        $base['filter_change_interval_months'] = $machine->filter_change_interval_months;
        $base['address'] = $machine->relationLoaded('address') && $machine->address !== null
            ? (new AddressResource($machine->address))->resolve($request)
            : null;
        $base['notes'] = $machine->notes;

        // Loaded by the service (latest 50 ordered DESC by scheduled_at)
        // — DO NOT use the relation default ordering here, the service
        // already applied the cap to keep the response under ~30 KB.
        if ($machine->relationLoaded('maintenanceLogs')) {
            $logs = $machine->maintenanceLogs;
            $base['maintenance_logs'] = MachineMaintenanceLogResource::collection($logs)
                ->resolve($request);

            $latestCompleted = $logs
                ->where('status', MaintenanceLogStatus::Completed)
                ->sortByDesc('completed_at')
                ->first();
            $base['latest_completed_log_at'] = $latestCompleted instanceof MachineMaintenanceLog
                && $latestCompleted->completed_at !== null
                    ? $latestCompleted->completed_at->toDateString()
                    : null;
        } else {
            $base['maintenance_logs'] = [];
            $base['latest_completed_log_at'] = null;
        }

        $base['total_logs_count'] = is_numeric($machine->getAttribute('maintenance_logs_count'))
            ? (int) $machine->getAttribute('maintenance_logs_count')
            : $machine->maintenanceLogs()->count();

        $base['open_requests_count'] = is_numeric($machine->getAttribute('maintenance_requests_count'))
            ? (int) $machine->getAttribute('maintenance_requests_count')
            : $machine->maintenanceRequests()
                ->whereIn('status', ['new', 'assigned', 'in_progress'])
                ->count();

        return $base;
    }
}
