<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Payments;

use Illuminate\Http\Request;

/**
 * Response shape for `GET /api/v1/payments/{id}/status`.
 *
 * Inherits the base allowlist and additionally suppresses
 * `payment_url` regardless of status — status-check responses must
 * never expose the checkout URL (replay-the-link attack surface).
 */
final class PaymentStatusResponseResource extends PaymentResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);
        $base['payment_url'] = null;
        $base['expires_at'] = null;
        $base['updated_at'] = $this->resource->updated_at->toIso8601String();

        return $base;
    }
}
