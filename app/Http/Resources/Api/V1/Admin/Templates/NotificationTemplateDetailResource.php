<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Templates;

/**
 * Single-row detail wrapper.
 *
 * For Phase 22, the list shape and detail shape are identical (the same
 * allowlist applies). The dedicated class keeps controllers explicit:
 * list endpoint returns {@see NotificationTemplateResource}, update
 * endpoint returns {@see NotificationTemplateDetailResource}.
 *
 * Inheritance reuses `toArray()`, so any future divergence (e.g. surface
 * recent edit history) can extend the detail class without affecting the
 * list shape.
 */
final class NotificationTemplateDetailResource extends NotificationTemplateResource
{
    // Shape identical to list — see parent::toArray().
}
