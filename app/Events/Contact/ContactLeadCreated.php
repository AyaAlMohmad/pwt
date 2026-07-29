<?php

declare(strict_types=1);

namespace App\Events\Contact;

use App\Events\BaseDomainEvent;
use App\Models\ContactLead;

/**
 * Fired immediately after a guest submits the contact form.
 *
 * Carries only the row id — the listener re-queries fresh state to
 * render the ops email. No `user_id` on the row (guest submissions),
 * so `actorUserId` is always null.
 *
 * See docs/architecture/contact.md § 9.2.
 */
final class ContactLeadCreated extends BaseDomainEvent
{
    public function __construct(
        public readonly int $contactLeadId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(ContactLead $lead): self
    {
        return new self(contactLeadId: $lead->id, actorUserId: null);
    }

    public function templateKey(): string
    {
        return 'contact.created_ops';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['contact_lead_id' => $this->contactLeadId];
    }
}
