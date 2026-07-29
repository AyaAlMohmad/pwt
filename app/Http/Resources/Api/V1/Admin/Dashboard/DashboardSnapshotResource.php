<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Dashboard;

use App\Services\Admin\Dto\DashboardSnapshotDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a {@see DashboardSnapshotDto} for the JSON response.
 *
 * The DTO is the cache boundary — both cached and freshly-computed
 * snapshots flow through this resource so the response shape stays
 * stable.
 *
 * @property-read DashboardSnapshotDto $resource
 */
final class DashboardSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var DashboardSnapshotDto $dto */
        $dto = $this->resource;

        return [
            'orders' => $dto->orders,
            'revenue' => $dto->revenue,
            'maintenance' => $dto->maintenance,
            'customers' => $dto->customers,
            'machines' => $dto->machines,
            'contact_leads' => $dto->contactLeads,
        ];
    }
}
