<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Maintenance;

use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Admin list-shape of a {@see MaintenanceRequest}.
 *
 * Distinct from customer `MaintenanceRequestResource`:
 *  - Embeds full customer PII (id/name/email/phone).
 *  - Surfaces `assigned_to.role` (full admin identity).
 *  - Adds `country_code`, `closed_at`, `is_trashed`.
 *  - `description_excerpt` (160 chars) — full body lives in detail.
 *
 * @property-read MaintenanceRequest $resource
 */
class AdminMaintenanceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var MaintenanceRequest $req */
        $req = $this->resource;

        return [
            'id' => $req->id,
            'public_id' => $req->public_id,
            'display_name' => $this->resolveDisplayName($req),
            'issue_type' => $req->issue_type->value,
            'description_excerpt' => Str::limit($req->description, 160, '…'),
            'status' => $req->status->value,
            'priority' => $req->priority->value,
            'preferred_date' => $req->preferred_date?->toDateString(),
            'customer' => $this->resolveCustomer($req),
            'machine' => $this->resolveMachine($req),
            'due' => $this->resolveDue($req),
            'last_service' => $this->resolveLastService($req),
            'assigned_to' => $this->resolveAssignedTo($req),
            'country_code' => $this->resolveCountryCode($req),
            'created_at' => $req->created_at->toIso8601String(),
            'closed_at' => $req->closed_at?->toIso8601String(),
            'is_trashed' => $req->trashed(),
        ];
    }

    protected function resolveDisplayName(MaintenanceRequest $req): string
    {
        return $req->displayName();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveCustomer(MaintenanceRequest $req): ?array
    {
        if (! $req->relationLoaded('user') || $req->user === null) {
            return null;
        }

        $user = $req->user;
        $phoneE164 = $user->phone !== null
            ? $user->phone_country_code.$user->phone
            : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $phoneE164,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveMachine(MaintenanceRequest $req): ?array
    {
        if (! $req->relationLoaded('machine') || $req->machine === null) {
            return null;
        }

        $machine = $req->machine;
        $product = $machine->relationLoaded('product') ? $machine->product : null;

        return [
            'id' => $machine->id,
            'public_id' => $machine->public_id,
            'display_name' => $machine->displayName(),
            'serial_number' => $machine->serial_number,
            'installed_at' => $machine->installed_at?->toDateString(),
            'product' => $product !== null
                ? ['code' => $product->code]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveAssignedTo(MaintenanceRequest $req): ?array
    {
        if (! $req->relationLoaded('assignedTo') || $req->assignedTo === null) {
            return null;
        }

        return $this->resolveActorSummary($req->assignedTo);
    }

    /**
     * Shared helper — full admin shape with role.
     *
     * @return array<string, mixed>
     */
    protected function resolveActorSummary(User $user): array
    {
        $role = null;
        if ($user->relationLoaded('roles')) {
            $names = $user->getRoleNames()->all();
            $role = $names === [] ? null : (string) $names[0];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $role,
        ];
    }

    protected function resolveCountryCode(MaintenanceRequest $req): ?string
    {
        if (! $req->relationLoaded('machine') || $req->machine === null) {
            return null;
        }

        return $req->machine->country_code;
    }

    /**
     * Next maintenance due date from the attached machine.
     */
    protected function resolveDue(MaintenanceRequest $req): ?string
    {
        if (! $req->relationLoaded('machine') || $req->machine === null) {
            return null;
        }

        return $req->machine->next_maintenance_due_at?->toDateString();
    }

    /**
     * Latest completed maintenance log date for the attached machine.
     */
    protected function resolveLastService(MaintenanceRequest $req): ?string
    {
        if (! $req->relationLoaded('machine') || $req->machine === null) {
            return null;
        }

        $machine = $req->machine;
        if (! $machine->relationLoaded('maintenanceLogs')) {
            return null;
        }

        $latest = $machine->maintenanceLogs
            ->filter(static fn ($log): bool => $log->status->value === 'completed' && $log->completed_at !== null)
            ->sortByDesc('completed_at')
            ->first();

        return $latest?->completed_at?->toDateString();
    }
}
