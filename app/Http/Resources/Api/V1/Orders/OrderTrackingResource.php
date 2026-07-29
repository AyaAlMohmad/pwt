<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Orders;

use App\Services\Orders\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection returned by `GET /orders/{id}/tracking`.
 *
 * The resource wraps an already-built array DTO produced by
 * {@see OrderService::tracking()}. We keep the
 * computation in the service so the resource itself is a thin shape-
 * mapper that the test suite can hit independently.
 *
 * @property-read array<string, mixed> $resource
 */
final class OrderTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return $payload;
    }
}
