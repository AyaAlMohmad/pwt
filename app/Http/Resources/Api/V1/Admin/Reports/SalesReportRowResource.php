<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Reports;

use App\Services\Reports\ReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Single row of the sales report.
 *
 * Source is a plain stdClass from the {@see ReportBuilder}
 * Query Builder paginator (not an Eloquent model), so we read raw
 * attributes. Customer phone is reassembled from country code + national
 * number.
 *
 * @property-read object $resource
 */
final class SalesReportRowResource extends JsonResource
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
            'customer' => [
                'id' => isset($r->customer_id) ? (int) $r->customer_id : null,
                'name' => isset($r->customer_name) ? (string) $r->customer_name : null,
                'email' => isset($r->customer_email) ? (string) $r->customer_email : null,
                'phone' => $this->phone($r->customer_phone_cc ?? null, $r->customer_phone ?? null),
            ],
            'country_code' => isset($r->country_code) ? (string) $r->country_code : null,
            'subtotal_amount' => $this->money($r->subtotal_amount ?? null),
            'discount_amount' => $this->money($r->discount_amount ?? null),
            'tax_amount' => $this->money($r->tax_amount ?? null),
            'shipping_amount' => $this->money($r->shipping_amount ?? null),
            'total_amount' => $this->money($r->total_amount ?? null),
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

    private function phone(mixed $cc, mixed $phone): ?string
    {
        $cc = is_string($cc) ? $cc : '';
        $phone = is_string($phone) ? $phone : '';
        if ($phone === '') {
            return null;
        }

        return $cc.$phone;
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
