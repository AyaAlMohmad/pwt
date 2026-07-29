<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\AuditLogs;

use App\Services\Admin\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Single row of the audit-log list.
 *
 * Source is a plain stdClass from the {@see AuditLogService}
 * Query Builder paginator (not Eloquent). The causer block surfaces the
 * actor's last-known id/name/email/role even if the user has since been
 * soft-deleted — the service uses LEFT JOIN to preserve that history.
 *
 * `properties` is the raw JSON blob emitted by Spatie's `activity()` —
 * super_admins need the full before/after diff. PII concerns are
 * mitigated by the role gate alone (super_admin only).
 *
 * @property-read object $resource
 */
final class AuditLogEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        $r = $this->resource;

        $causerId = isset($r->causer_id) ? (int) $r->causer_id : null;

        return [
            'id' => (int) ($r->id ?? 0),
            'log_name' => isset($r->log_name) ? (string) $r->log_name : null,
            'description' => (string) ($r->description ?? ''),
            'event' => isset($r->event) ? (string) $r->event : null,
            'subject_type' => isset($r->subject_type) ? (string) $r->subject_type : null,
            'subject_id' => isset($r->subject_id) ? (int) $r->subject_id : null,
            'causer' => [
                'id' => $causerId,
                'name' => isset($r->causer_name) ? (string) $r->causer_name : null,
                'email' => isset($r->causer_email) ? (string) $r->causer_email : null,
                'role' => isset($r->causer_role) ? (string) $r->causer_role : null,
            ],
            'properties' => $this->properties(),
            'created_at' => $this->iso($r->created_at ?? null),
        ];
    }

    /**
     * Properties may arrive as a JSON string (DB::table raw rows) or an
     * already-decoded array (factory / test paths). Normalise to array.
     *
     * @return array<mixed>|null
     */
    private function properties(): ?array
    {
        $raw = $this->resource->properties ?? null;
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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
