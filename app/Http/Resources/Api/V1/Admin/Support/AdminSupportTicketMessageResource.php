<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Support;

use App\Enums\SupportTicketMessageSenderType;
use App\Http\Resources\Api\V1\Support\SupportTicketAttachmentResource;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape of a single {@see SupportTicketMessage}.
 *
 * Unlike the customer-side resource, this exposes:
 *  - `is_internal_note` verbatim (admins see all messages including
 *    internal notes — see docs/architecture/admin-support.md § 4.3),
 *  - the FULL `sender{id,name,role}` shape (not first-name anonymised),
 *  - the same attachment shape as the customer resource (the file
 *    layer's signed-URL contract is identical for admins).
 *
 * @property-read SupportTicketMessage $resource
 */
final class AdminSupportTicketMessageResource extends JsonResource
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
            'sender' => $this->resolveSender($m),
            'body' => $m->body,
            'is_internal_note' => (bool) $m->is_internal_note,
            'created_at' => $m->created_at->toIso8601String(),
            'attachments' => $m->relationLoaded('attachments')
                ? SupportTicketAttachmentResource::collection($m->attachments)->resolve($request)
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSender(SupportTicketMessage $m): array
    {
        if ($m->sender_type === SupportTicketMessageSenderType::System) {
            return [
                'id' => null,
                'name' => (string) __('support.system_sender'),
                'role' => 'system',
            ];
        }

        if (! $m->relationLoaded('sender') || $m->sender === null) {
            return [
                'id' => $m->sender_user_id,
                'name' => (string) __('support.support_fallback_name'),
                'role' => null,
            ];
        }

        return $this->actorSummary($m->sender);
    }

    /**
     * @return array<string, mixed>
     */
    private function actorSummary(User $user): array
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
}
