<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Privacy-anonymised actor shape used on `assigned_to`, `actor` (inside
 * status_history), etc.
 *
 * Customers can see "Hassan from PWT" but never the full last name,
 * email, phone, or numeric user id. See
 * docs/architecture/machines-maintenance.md § 2.4 / § 4.4.
 *
 * @property-read User $resource
 */
final class MaintenanceActorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        // Best-effort first-name extraction: split on whitespace and
        // take the first segment. Fall back to the whole name when no
        // whitespace is present.
        $name = trim((string) $user->name);
        $firstName = $name === ''
            ? 'PWT'
            : (string) (preg_split('/\s+/', $name)[0] ?? $name);

        return [
            'first_name' => $firstName,
        ];
    }
}
