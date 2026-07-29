<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Rfq;

use App\Models\RfqRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read RfqRequest $resource
 */
class AdminRfqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);

        /** @var RfqRequest $rfq */
        $rfq = $this->resource;

        return [
            'id' => $rfq->id,
            'company_name' => $rfq->company_name,
            'email' => $rfq->email,
            'phone' => $rfq->phone,
            'notes' => $rfq->notes,
            'status' => $rfq->status,
            'processed' => $rfq->processed,
            'processed_by' => $this->resolveProcessedBy($rfq),
            'processed_at' => $rfq->processed_at?->toIso8601String(),
            'created_at' => $rfq->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveProcessedBy(RfqRequest $rfq): ?array
    {
        if (! $rfq->relationLoaded('processedBy') || $rfq->processedBy === null) {
            return null;
        }

        $user = $rfq->processedBy;

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
