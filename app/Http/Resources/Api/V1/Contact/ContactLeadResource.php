<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Contact;

use App\Models\ContactLead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing shape of a freshly-created {@see ContactLead}.
 *
 * Minimal allowlist: id + name + email + subject + source + created_at.
 * The original `message` body is intentionally NOT echoed back — keeps
 * the response light and avoids replaying free-form input that may
 * contain PII the front-end shouldn't render twice.
 *
 * See docs/architecture/contact.md § 4.1.
 *
 * @property-read ContactLead $resource
 */
class ContactLeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ContactLead $lead */
        $lead = $this->resource;

        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'email' => $lead->email,
            'subject' => $lead->subject,
            'source' => $lead->source->value,
            'type' => $lead->type->value,
            'created_at' => $lead->created_at->toIso8601String(),
        ];
    }
}
