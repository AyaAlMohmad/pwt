<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Single row of the maintenance report.
 *
 * @property-read object $resource
 */
final class MaintenanceReportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        $r = $this->resource;

        $serial = (string) ($r->serial_number ?? '');
        if (! empty($r->machine_deleted_at ?? null) && $serial !== '') {
            $serial .= ' (deleted)';
        }

        return [
            'id' => (int) ($r->id ?? 0),
            'public_id' => (string) ($r->public_id ?? ''),
            'created_at' => $this->iso($r->created_at ?? null),
            'status' => (string) ($r->status ?? ''),
            'priority' => (string) ($r->priority ?? ''),
            'issue_type' => (string) ($r->issue_type ?? ''),
            'customer' => [
                'id' => isset($r->customer_id) ? (int) $r->customer_id : null,
                'name' => isset($r->customer_name) ? (string) $r->customer_name : null,
                'email' => isset($r->customer_email) ? (string) $r->customer_email : null,
                'phone' => $this->phone($r->customer_phone_cc ?? null, $r->customer_phone ?? null),
            ],
            'machine' => [
                'id' => isset($r->machine_id) ? (int) $r->machine_id : null,
                'serial_number' => $serial !== '' ? $serial : null,
                'product_code' => isset($r->product_code) ? (string) $r->product_code : null,
                'country_code' => isset($r->machine_country) ? (string) $r->machine_country : null,
            ],
            'assigned_to' => isset($r->technician_id) ? [
                'id' => (int) $r->technician_id,
                'name' => (string) ($r->technician_name ?? ''),
            ] : null,
            'closed_at' => $this->iso($r->closed_at ?? null),
            'resolution_hours' => isset($r->resolution_hours) && is_numeric($r->resolution_hours)
                ? (float) $r->resolution_hours
                : null,
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
