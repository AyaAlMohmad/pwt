<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Machines;

use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\MaintenanceRequest;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Full single-machine shape returned by GET /api/v1/admin/machines/{id}
 * and the write endpoints.
 *
 * Adds: notes, location_label, latest_completed_log_at, total_logs_count,
 *       maintenance_logs[] (full admin shape — technician + created_by),
 *       maintenance_requests[] (admin summary), deleted_at, order embed.
 *
 * @property-read Machine $resource
 */
final class AdminMachineDetailResource extends AdminMachineResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Machine $machine */
        $machine = $this->resource;
        $base = parent::toArray($request);

        $base['notes'] = $machine->notes;
        $base['location_label'] = $machine->location_label;

        $latest = null;
        if ($machine->relationLoaded('maintenanceLogs')) {
            $logs = $machine->maintenanceLogs;
            $latestCompleted = $logs
                ->filter(static fn (MachineMaintenanceLog $l): bool => $l->status->value === 'completed' && $l->completed_at !== null)
                ->sortByDesc('completed_at')
                ->first();
            $latest = $latestCompleted?->completed_at?->toDateString();
        }
        $base['latest_completed_log_at'] = $latest;

        $totalLogsCount = $machine->getAttribute('total_logs_count');
        $base['total_logs_count'] = is_numeric($totalLogsCount) ? (int) $totalLogsCount : 0;

        $base['maintenance_logs'] = $machine->relationLoaded('maintenanceLogs')
            ? $machine->maintenanceLogs->map(fn (MachineMaintenanceLog $log) => $this->resolveLog($log))->values()->all()
            : [];

        $base['maintenance_requests'] = $machine->relationLoaded('maintenanceRequests')
            ? $machine->maintenanceRequests->map(fn (MaintenanceRequest $req) => $this->resolveRequest($req))->values()->all()
            : [];

        $base['latest_maintenance_request'] = $this->resolveLatestMaintenanceRequest($machine);

        $base['deleted_at'] = $machine->deleted_at?->toIso8601String();

        if ($machine->relationLoaded('order') && $machine->order !== null) {
            /** @var Order $order */
            $order = $machine->order;
            $base['order'] = [
                'id' => $order->id,
                'public_id' => $order->public_id,
                'status' => $order->status->value,
            ];
        } else {
            $base['order'] = null;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLog(MachineMaintenanceLog $log): array
    {
        $technician = $log->relationLoaded('technician') ? $log->technician : null;
        $createdBy = $log->relationLoaded('createdBy') ? $log->createdBy : null;

        return [
            'id' => $log->id,
            'status' => $log->status->value,
            'scheduled_at' => $log->scheduled_at->toDateString(),
            'completed_at' => $log->completed_at?->toDateString(),
            'notes' => $log->notes,
            'source' => $log->source->value,
            'maintenance_request_id' => $log->maintenance_request_id,
            'technician' => $technician !== null
                ? ['id' => $technician->id, 'name' => $technician->name]
                : null,
            'created_by' => $createdBy !== null
                ? ['id' => $createdBy->id, 'name' => $createdBy->name]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRequest(MaintenanceRequest $req): array
    {
        $assignedTo = $req->relationLoaded('assignedTo') ? $req->assignedTo : null;

        return [
            'id' => $req->id,
            'public_id' => $req->public_id,
            'status' => $req->status->value,
            'priority' => $req->priority->value,
            'issue_type' => $req->issue_type->value,
            'assigned_to' => $assignedTo !== null
                ? ['id' => $assignedTo->id, 'name' => $assignedTo->name]
                : null,
            'created_at' => $req->created_at->toIso8601String(),
            'closed_at' => $req->closed_at?->toIso8601String(),
        ];
    }
}
