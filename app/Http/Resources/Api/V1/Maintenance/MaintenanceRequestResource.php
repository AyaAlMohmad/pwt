<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Customer-facing LIST shape of a {@see MaintenanceRequest}.
 *
 * `description_excerpt` is the first 140 characters with an ellipsis.
 * `machine` is a small embed; never returns the customer-facing
 * full machine resource on a list endpoint to keep payloads compact.
 *
 * @property-read MaintenanceRequest $resource
 */
class MaintenanceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MaintenanceRequest $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'public_id' => $r->public_id,
            'display_name' => $this->resolveDisplayName($r),
            'issue_type' => $r->issue_type->value,
            'description_excerpt' => (string) Str::limit($r->description, 140, '…'),
            'status' => $r->status->value,
            'priority' => $r->priority->value,
            'preferred_date' => $r->preferred_date?->toDateString(),
            'preferred_time' => $r->preferred_time,
            'machine' => $this->resolveMachineSmall($r),
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }

    protected function resolveDisplayName(MaintenanceRequest $r): string
    {
        return $r->displayName();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveMachineSmall(MaintenanceRequest $r): ?array
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
            'product' => $product,
        ];
    }
}
