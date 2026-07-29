<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Models\MachineMaintenanceLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Single maintenance log entry inside the `MachineDetail` payload.
 *
 * Hides actor user ids — only the operational outcome (status, dates,
 * source, optional request id, optional notes) is exposed.
 *
 * @property-read MachineMaintenanceLog $resource
 */
final class MachineMaintenanceLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MachineMaintenanceLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'status' => $log->status->value,
            'scheduled_at' => $log->scheduled_at->toDateString(),
            'completed_at' => $log->completed_at?->toDateString(),
            'notes' => $log->notes,
            'source' => $log->source->value,
            'maintenance_request_id' => $log->maintenance_request_id,
        ];
    }
}
