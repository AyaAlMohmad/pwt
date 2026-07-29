<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Support;

use App\Enums\SupportTicketMessageSenderType;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing shape of a single {@see SupportTicketMessage}.
 *
 * `is_internal_note=true` rows MUST NEVER reach this resource — the
 * service layer filters at the query level. As defence-in-depth this
 * resource ALSO returns `null` from `toArray()` (rendered as
 * `whenLoaded`-style empty) if it ever encounters an internal note —
 * which the controller / dispatcher will skip during collection mapping.
 *
 * Sender name is anonymised to first-name only via the same convention
 * as Phase 10's `MaintenanceActorResource`.
 *
 * @property-read SupportTicketMessage $resource
 */
final class SupportTicketMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicketMessage $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'sender_type' => $m->sender_type->value,
            'sender_first_name' => $this->resolveSenderFirstName($m),
            'body' => $m->body,
            'created_at' => $m->created_at->toIso8601String(),
            'attachments' => $m->relationLoaded('attachments')
                ? SupportTicketAttachmentResource::collection($m->attachments)->resolve($request)
                : [],
        ];
    }

    private function resolveSenderFirstName(SupportTicketMessage $m): string
    {
        if ($m->sender_type === SupportTicketMessageSenderType::System) {
            return (string) __('support.system_sender');
        }

        if (! $m->relationLoaded('sender') || $m->sender === null) {
            return (string) __('support.support_fallback_name');
        }

        $name = trim((string) $m->sender->name);
        if ($name === '') {
            return (string) __('support.support_fallback_name');
        }

        $parts = preg_split('/\s+/', $name);
        if (! is_array($parts)) {
            return $name;
        }

        return (string) $parts[0];
    }
}
