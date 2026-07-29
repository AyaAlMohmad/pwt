<?php

declare(strict_types=1);

use App\Enums\ContactLeadSource;
use App\Events\Contact\ContactLeadCreated;
use App\Models\ContactLead;
use App\Services\Contact\ContactLeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ContactLeadService — service-level tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/contact.md § 9.1.
|   - submit() persists the row and fires ContactLeadCreated after commit.
|   - submit() with a non-empty honeypot returns a phantom (non-persisted)
|     model, fires NO event, writes NO row.
|   - IP + user_agent are captured server-side and stored.
*/

beforeEach(function (): void {
    $this->service = new ContactLeadService;
});

describe('ContactLeadService::submit() — happy path', function (): void {
    it('persists a contact_leads row with all provided fields', function (): void {
        $payload = [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'Quote request',
            'message' => 'Please send me a quote for installing the PW50M at home.',
            'source' => 'web',
            'phone' => '+971501234567',
            'phone_country_code' => '+971',
        ];

        $lead = $this->service->submit($payload, '127.0.0.1', 'PWT-TestClient/1.0');

        expect($lead)->toBeInstanceOf(ContactLead::class);
        expect($lead->id)->toBeGreaterThan(0);
        expect($lead->exists)->toBeTrue();

        $this->assertDatabaseHas('contact_leads', [
            'id' => $lead->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+971501234567',
            'phone_country_code' => '+971',
            'subject' => 'Quote request',
            'source' => 'web',
            'ip' => '127.0.0.1',
            'user_agent' => 'PWT-TestClient/1.0',
            'processed' => false,
        ]);
    });

    it('fires ContactLeadCreated after commit with the persisted lead id (and actorUserId=null)', function (): void {
        Event::fake([ContactLeadCreated::class]);

        $lead = $this->service->submit([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'Event probe',
            'message' => 'Just verifying that ContactLeadCreated fires after commit.',
        ], '127.0.0.1', 'agent');

        Event::assertDispatched(
            ContactLeadCreated::class,
            fn (ContactLeadCreated $e): bool => $e->contactLeadId === $lead->id
                && $e->actorUserId === null,
        );
        Event::assertDispatchedTimes(ContactLeadCreated::class, 1);
    });

    it('defaults source to "web" when omitted from the payload', function (): void {
        $lead = $this->service->submit([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'No source provided',
            'message' => 'I have no idea what source value to send, so I left it blank.',
        ], '127.0.0.1', 'agent');

        expect($lead->source)->toBe(ContactLeadSource::Web);
        $this->assertDatabaseHas('contact_leads', [
            'id' => $lead->id,
            'source' => 'web',
        ]);
    });

    it('coerces an unrecognised source string to the default "web" instead of throwing', function (): void {
        $lead = $this->service->submit([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'Junk source value',
            'message' => 'Source carries an unexpected string — service must fall back, not throw.',
            'source' => 'fax-machine',
        ], '127.0.0.1', 'agent');

        expect($lead->source)->toBe(ContactLeadSource::Web);
    });

    it('truncates an over-long ip to 45 characters', function (): void {
        $longIp = str_repeat('a', 60);

        $lead = $this->service->submit([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'IP truncation probe',
            'message' => 'Confirm ip is truncated to fit the 45-char column width.',
        ], $longIp, 'agent');

        expect(mb_strlen((string) $lead->ip))->toBe(45);
    });

    it('truncates an over-long user_agent to 255 characters', function (): void {
        $longUa = str_repeat('U', 400);

        $lead = $this->service->submit([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'UA truncation probe',
            'message' => 'Confirm user_agent is truncated to fit the 255-char column width.',
        ], '127.0.0.1', $longUa);

        expect(mb_strlen((string) $lead->user_agent))->toBe(255);
    });

    it('stores null for phone and phone_country_code when both are blank', function (): void {
        $lead = $this->service->submit([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'Phoneless lead',
            'message' => 'No phone provided — service must persist null, not the empty string.',
            'phone' => '',
            'phone_country_code' => '',
        ], '127.0.0.1', 'agent');

        expect($lead->phone)->toBeNull();
        expect($lead->phone_country_code)->toBeNull();
    });
});

describe('ContactLeadCreated event helpers', function (): void {
    it('fromModel() builds an event carrying the lead id and a null actorUserId', function (): void {
        $lead = ContactLead::factory()->create();

        $event = ContactLeadCreated::fromModel($lead);

        expect($event->contactLeadId)->toBe($lead->id);
        expect($event->actorUserId)->toBeNull();
    });

    it('templateKey() returns the ops-only template key', function (): void {
        $event = new ContactLeadCreated(contactLeadId: 7, actorUserId: null);

        expect($event->templateKey())->toBe('contact.created_ops');
    });

    it('payload() returns the contact_lead_id keyed array', function (): void {
        $event = new ContactLeadCreated(contactLeadId: 99, actorUserId: null);

        expect($event->payload())->toBe(['contact_lead_id' => 99]);
    });
});

describe('ContactLeadService::submit() — honeypot path', function (): void {
    it('returns a phantom non-persisted lead and fires NO event when website is filled', function (): void {
        Event::fake([ContactLeadCreated::class]);

        $lead = $this->service->submit([
            'name' => 'Definitely Not A Bot',
            'email' => 'bot@spam.example',
            'subject' => 'Buy cheap stuff',
            'message' => 'This is a bot submission that should be silently dropped.',
            'website' => 'http://spambot.example/cialis',
        ], '203.0.113.1', 'bot/1.0');

        expect($lead)->toBeInstanceOf(ContactLead::class);
        expect($lead->exists)->toBeFalse();
        expect($lead->id)->toBe(0);

        $this->assertDatabaseCount('contact_leads', 0);
        Event::assertNotDispatched(ContactLeadCreated::class);
    });

    it('treats a whitespace-only honeypot value as empty (no trap fires)', function (): void {
        Event::fake([ContactLeadCreated::class]);

        $lead = $this->service->submit([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'subject' => 'Whitespace honeypot probe',
            'message' => 'Whitespace in the honeypot should not behave as a bot trip.',
            'website' => '   ',
        ], '127.0.0.1', 'agent');

        expect($lead->exists)->toBeTrue();
        $this->assertDatabaseCount('contact_leads', 1);
        Event::assertDispatched(ContactLeadCreated::class);
    });
});
