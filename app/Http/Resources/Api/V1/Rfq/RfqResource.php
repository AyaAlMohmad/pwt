<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Rfq;

use App\Models\RfqRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RfqRequest
 */
final class RfqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);

        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'status' => $this->status,
            'processed' => $this->processed,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
