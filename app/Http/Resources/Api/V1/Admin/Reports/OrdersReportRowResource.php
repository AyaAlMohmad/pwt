<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Single row of the orders report.
 *
 * @property-read object $resource
 */
final class OrdersReportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        $r = $this->resource;

        return [
            'order_id' => (int) ($r->order_id ?? 0),
            'order_public_id' => (string) ($r->order_public_id ?? ''),
            'placed_at' => $this->iso($r->placed_at ?? null),
            'status' => (string) ($r->status ?? ''),
            'term' => (string) ($r->term ?? ''),
            'payment_method' => (string) ($r->payment_method ?? ''),
            'customer_name' => isset($r->customer_name) ? (string) $r->customer_name : null,
            'customer_email' => isset($r->customer_email) ? (string) $r->customer_email : null,
            'country_code' => isset($r->country_code) ? (string) $r->country_code : null,
            'total_amount' => number_format((float) ($r->total_amount ?? 0), 2, '.', ''),
            'currency' => (string) ($r->currency ?? 'AED'),
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }
        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
