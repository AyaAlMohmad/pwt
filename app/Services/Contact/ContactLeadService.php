<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Enums\ContactLeadSource;
use App\Enums\ContactLeadType;
use App\Events\Contact\ContactLeadCreated;
use App\Models\ContactLead;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the public guest contact-form surface.
 *
 *   - `submit()` — persists the lead row and fires
 *                  {@see ContactLeadCreated} via DB::afterCommit so the
 *                  ops-inbox email never references a rolled-back row.
 *                  When the honeypot `website` field is non-empty, the
 *                  call is silently dropped: a transient (non-persisted)
 *                  model is returned so the controller can still render
 *                  a normal 201 envelope without tipping off the bot.
 *
 * See docs/architecture/contact.md § 9.1.
 */
final class ContactLeadService
{
    /**
     * Submit a contact-form payload.
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     subject: string,
     *     message: string,
     *     phone?: string|null,
     *     phone_country_code?: string|null,
     *     source?: string|null,
     *     type?: string|null,
     *     website?: string|null,
     * }  $payload
     */
    public function submit(array $payload, ?string $ip, ?string $userAgent): ContactLead
    {
        // Honeypot trap — bots fill hidden `website` fields. We return
        // a phantom (non-persisted) model so the controller's response
        // is indistinguishable from a real success.
        $honeypot = isset($payload['website']) ? trim((string) $payload['website']) : '';
        if ($honeypot !== '') {
            return $this->buildPhantom($payload);
        }

        $source = isset($payload['source']) && $payload['source'] !== ''
            ? ContactLeadSource::tryFrom((string) $payload['source']) ?? ContactLeadSource::default()
            : ContactLeadSource::default();

        $type = isset($payload['type']) && $payload['type'] !== ''
            ? ContactLeadType::tryFrom((string) $payload['type']) ?? ContactLeadType::default()
            : ContactLeadType::default();

        $attributes = [
            'name' => (string) $payload['name'],
            'email' => (string) $payload['email'],
            'phone' => isset($payload['phone']) && $payload['phone'] !== ''
                ? (string) $payload['phone']
                : null,
            'phone_country_code' => isset($payload['phone_country_code']) && $payload['phone_country_code'] !== ''
                ? (string) $payload['phone_country_code']
                : null,
            'subject' => (string) $payload['subject'],
            'message' => (string) $payload['message'],
            'source' => $source->value,
            'type' => $type->value,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'processed' => false,
        ];

        return DB::transaction(function () use ($attributes): ContactLead {
            /** @var ContactLead $lead */
            $lead = ContactLead::query()->create($attributes);

            $leadId = (int) $lead->id;
            DB::afterCommit(static function () use ($leadId): void {
                event(new ContactLeadCreated(contactLeadId: $leadId, actorUserId: null));
            });

            return $lead;
        });
    }

    /**
     * Build a non-persisted ContactLead reflecting the request so the
     * honeypot path can return an indistinguishable response shape.
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     subject: string,
     *     message: string,
     *     source?: string|null,
     *     type?: string|null,
     * }  $payload
     */
    private function buildPhantom(array $payload): ContactLead
    {
        $source = isset($payload['source']) && $payload['source'] !== ''
            ? ContactLeadSource::tryFrom((string) $payload['source']) ?? ContactLeadSource::default()
            : ContactLeadSource::default();

        $type = isset($payload['type']) && $payload['type'] !== ''
            ? ContactLeadType::tryFrom((string) $payload['type']) ?? ContactLeadType::default()
            : ContactLeadType::default();

        $lead = new ContactLead;
        $lead->forceFill([
            'id' => 0,
            'name' => (string) $payload['name'],
            'email' => (string) $payload['email'],
            'subject' => (string) $payload['subject'],
            'source' => $source->value,
            'type' => $type->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $lead;
    }
}
