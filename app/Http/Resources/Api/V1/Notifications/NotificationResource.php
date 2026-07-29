<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notifications;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing notification representation.
 *
 * Allowlists the customer-visible fields per
 * docs/architecture/notifications.md § 6.1. Channel / delivery_status /
 * failure_reason / sent_at are NOT exposed.
 *
 * @property-read Notification $resource
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Notification $n */
        $n = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $n->id,
            'public_id' => $n->public_id,
            'type' => $n->type,
            'title' => (string) $n->getTranslation('title', $locale),
            'body' => (string) $n->getTranslation('body', $locale),
            'data' => is_array($n->data) ? $n->data : null,
            'action_url' => $n->action_url,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
