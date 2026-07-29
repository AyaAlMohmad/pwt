<?php

declare(strict_types=1);

namespace App\Events;

use App\Listeners\NotificationListener;
use App\Services\Notifications\TemplateRenderer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Base class for every domain event the
 * {@see NotificationListener} subscribes to.
 *
 * Subclasses MUST carry only primitive scalars (IDs, codes, optional
 * notes) — NOT full Eloquent instances. The listener re-queries fresh
 * state from the database when it fans out to channels. This keeps
 * queued event payloads tiny, immune to stale-snapshot bugs, and
 * PII-light.
 *
 * See docs/architecture/notifications.md § 3.2.
 */
abstract class BaseDomainEvent
{
    use Dispatchable;
    use SerializesModels;

    public Carbon $occurredAt;

    public ?int $actorUserId;

    public function __construct(?int $actorUserId = null)
    {
        $this->actorUserId = $actorUserId;
        $this->occurredAt = Carbon::now();
    }

    /**
     * Stable template lookup key — e.g. `order.confirmed`,
     * `maintenance.assigned`. Must match a row in
     * `notification_templates.key`.
     */
    abstract public function templateKey(): string;

    /**
     * Plain-PHP array that {@see TemplateRenderer}
     * substitutes against `{{placeholder}}` tokens in the template body.
     *
     * Implementations should return only safe scalars (no closures, no
     * Eloquent models). Models should be re-loaded inside the listener
     * if richer context is required for the template.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;
}
