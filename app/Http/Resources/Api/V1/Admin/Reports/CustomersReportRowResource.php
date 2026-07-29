<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Single row of the customers report.
 *
 * @property-read object $resource
 */
final class CustomersReportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        $r = $this->resource;

        return [
            'id' => (int) ($r->id ?? 0),
            'name' => isset($r->name) ? (string) $r->name : null,
            'email' => isset($r->email) ? (string) $r->email : null,
            'phone' => $this->phone($r->phone_country_code ?? null, $r->phone ?? null),
            'country_code' => isset($r->country_code) ? (string) $r->country_code : null,
            'is_active' => (bool) ($r->is_active ?? false),
            'created_at' => $this->iso($r->created_at ?? null),
            'order_count' => (int) ($r->order_count ?? 0),
            'total_spend' => number_format((float) ($r->total_spend ?? 0), 2, '.', ''),
            'currency' => 'AED',
            'last_order_at' => $this->iso($r->last_order_at ?? null),
            'machines_count' => (int) ($r->machines_count ?? 0),
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

    private function phone(mixed $cc, mixed $phone): ?string
    {
        $cc = is_string($cc) ? $cc : '';
        $phone = is_string($phone) ? $phone : '';
        if ($phone === '') {
            return null;
        }

        return $cc.$phone;
    }
}
