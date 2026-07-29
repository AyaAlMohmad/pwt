<?php

declare(strict_types=1);

use App\Events\Contact\ContactLeadCreated;
use App\Models\ContactLead;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/contact (Phase 13 — public guest contact form)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/contact.md §§ 1, 3, 4, 5. Single public POST
| that persists a `contact_leads` row, fires `ContactLeadCreated`, and
| triggers an ops-inbox email via NotificationListener. Rate-limited at
| 5/min/IP via the `contact-form` named limiter. The `message` field is
| NEVER echoed back in the response.
*/

beforeEach(function (): void {
    // Each test starts with a clean limiter bucket so the per-IP counter
    // never bleeds across tests (every Pest test re-uses 127.0.0.1).
    RateLimiter::clear('contact-form');
    RateLimiter::clear('contact-form:127.0.0.1');
});

/**
 * @return array<string, mixed>
 */
function validContactPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'subject' => 'I need a price quote',
        'message' => 'I would like to learn more about the PW50M model and pricing for Dubai.',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/contact — happy path', function (): void {
    it('returns 201 with the success envelope + ContactLeadResource shape and persists a contact_leads row', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload());

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'email', 'subject', 'source', 'created_at'],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.subject', 'I need a price quote')
            ->assertJsonPath('data.source', 'web');

        $this->assertDatabaseHas('contact_leads', [
            'email' => 'jane@example.com',
            'subject' => 'I need a price quote',
            'source' => 'web',
            'processed' => false,
            'processed_by_user_id' => null,
            'processed_at' => null,
        ]);
    });

    it('captures the request ip and user-agent server-side (never from the request body)', function (): void {
        $this->withHeaders([
            'User-Agent' => 'PWT-TestClient/1.0',
        ])->postJson('/api/v1/contact', validContactPayload([
            // The client tries to spoof — should be ignored, server overrides.
            'ip' => '1.2.3.4',
            'user_agent' => 'evil-bot/9000',
        ]))->assertCreated();

        /** @var ContactLead $lead */
        $lead = ContactLead::query()->latest('id')->first();

        expect($lead)->not->toBeNull();
        expect($lead->ip)->not->toBeNull();
        expect($lead->ip)->not->toBe('1.2.3.4');
        expect($lead->user_agent)->toBe('PWT-TestClient/1.0');
    });

    it('defaults source to "web" when omitted from the payload', function (): void {
        $payload = validContactPayload();
        unset($payload['source']);

        $response = $this->postJson('/api/v1/contact', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.source', 'web');

        $this->assertDatabaseHas('contact_leads', [
            'email' => 'jane@example.com',
            'source' => 'web',
        ]);
    });

    it('persists an explicit source value of "mobile" when provided', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'source' => 'mobile',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.source', 'mobile');

        $this->assertDatabaseHas('contact_leads', [
            'email' => 'jane@example.com',
            'source' => 'mobile',
        ]);
    });

    it('persists phone + phone_country_code when both are supplied', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'phone' => '+971501234567',
            'phone_country_code' => '+971',
        ]));

        $response->assertCreated();

        $this->assertDatabaseHas('contact_leads', [
            'email' => 'jane@example.com',
            'phone' => '+971501234567',
            'phone_country_code' => '+971',
        ]);
    });

    it('does NOT echo back the submitted message field in the response payload', function (): void {
        $secret = 'This message body is full of sensitive PII that must not be replayed.';

        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'message' => $secret.' Some boilerplate to meet the min-length requirement.',
        ]));

        $response->assertCreated();

        $body = $response->json();
        $flat = json_encode($body);
        expect($flat)->not->toContain($secret);
        // Resource exposes only id/name/email/subject/source/created_at.
        expect($body['data'])->not->toHaveKey('message');
    });
});

/*
|--------------------------------------------------------------------------
| Honeypot — spambot trap
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/contact — honeypot', function (): void {
    it('returns a 201 success-shaped response when the website honeypot is filled, but persists NO row', function (): void {
        Event::fake([ContactLeadCreated::class]);

        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'website' => 'http://spambot.example/buy-cheap-cialis',
        ]));

        // Indistinguishable from real success — the bot must not know it was filtered.
        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['id', 'name', 'email', 'subject', 'source']]);

        // Phantom — id is the placeholder 0 the service sets in forceFill.
        expect($response->json('data.id'))->toBe(0);

        $this->assertDatabaseCount('contact_leads', 0);
        Event::assertNotDispatched(ContactLeadCreated::class);
    });
});

/*
|--------------------------------------------------------------------------
| Validation failures
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/contact — validation', function (): void {
    it('returns 422 with errors.name when name is missing', function (): void {
        $payload = validContactPayload();
        unset($payload['name']);

        $response = $this->postJson('/api/v1/contact', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name']])
            ->assertJsonPath('code', 'ERR_VALIDATION');

        $this->assertDatabaseCount('contact_leads', 0);
    });

    it('returns 422 with errors.email when email is missing', function (): void {
        $payload = validContactPayload();
        unset($payload['email']);

        $response = $this->postJson('/api/v1/contact', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    });

    it('returns 422 with errors.subject when subject is missing', function (): void {
        $payload = validContactPayload();
        unset($payload['subject']);

        $response = $this->postJson('/api/v1/contact', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['subject']]);
    });

    it('returns 422 with errors.message when message is missing', function (): void {
        $payload = validContactPayload();
        unset($payload['message']);

        $response = $this->postJson('/api/v1/contact', $payload);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['message']]);
    });

    it('returns 422 with errors.email when email is not a valid RFC address', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'email' => 'not-an-email-address',
        ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    });

    it('returns 422 with errors.subject when subject exceeds 200 chars', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'subject' => str_repeat('a', 201),
        ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['subject']]);
    });

    it('returns 422 with errors.message when message exceeds 5000 chars', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'message' => str_repeat('a', 5001),
        ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['message']]);
    });

    it('returns 422 with errors.source for an invalid enum value', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'source' => 'fax-machine',
        ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['source']]);
    });

    it('returns 422 with errors.phone_country_code when phone_country_code has a bad shape', function (): void {
        $response = $this->postJson('/api/v1/contact', validContactPayload([
            'phone' => '501234567',
            'phone_country_code' => 'XYZ',
        ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['phone_country_code']]);
    });
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/contact — rate limit', function (): void {
    it('returns 429 ERR_RATE_LIMITED after the 5/min/IP threshold is exceeded', function (): void {
        $hit429 = false;
        $statuses = [];

        for ($i = 0; $i < 6; $i++) {
            $resp = $this->postJson('/api/v1/contact', validContactPayload([
                'subject' => 'Rate-limit probe #'.$i,
                'message' => 'Probe message '.$i.' — long enough to satisfy the min-length validator.',
            ]));
            $statuses[] = $resp->status();
            if ($resp->status() === 429) {
                $hit429 = true;
                $resp->assertJsonPath('code', 'ERR_RATE_LIMITED');
                break;
            }
        }

        expect($hit429)->toBeTrue('Expected the 6th request to trip the 5/min limiter; got statuses: '.implode(',', $statuses));
    });
});

/*
|--------------------------------------------------------------------------
| Domain event dispatch
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/contact — events', function (): void {
    it('dispatches ContactLeadCreated exactly once with the new lead id after the row is persisted', function (): void {
        Event::fake([ContactLeadCreated::class]);

        $response = $this->postJson('/api/v1/contact', validContactPayload());

        $response->assertCreated();
        $leadId = (int) $response->json('data.id');
        expect($leadId)->toBeGreaterThan(0);

        Event::assertDispatched(
            ContactLeadCreated::class,
            fn (ContactLeadCreated $e): bool => $e->contactLeadId === $leadId
                && $e->actorUserId === null,
        );
        Event::assertDispatchedTimes(ContactLeadCreated::class, 1);
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual (AR)
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/contact — bilingual', function (): void {
    it('returns the Arabic envelope message on validation failure when Accept-Language is ar', function (): void {
        $response = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/contact', [
                // Missing required fields trips the validator under AR locale.
                'subject' => 'مساعدة في الطلب',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');

        // At least one of the AR-keyed validation messages must surface.
        $errors = $response->json('errors');
        expect($errors)->toBeArray();

        $flat = json_encode($errors, JSON_UNESCAPED_UNICODE);
        // The bilingual contact lang file uses Arabic script for every key.
        // Confirm the Arabic byte sequence shows up at least once across the
        // returned messages — proof we resolved AR copy, not the EN fallback.
        expect(preg_match('/\p{Arabic}/u', $flat))->toBe(1);
    });

    it('returns the Arabic thank-you envelope message on success when Accept-Language is ar', function (): void {
        $response = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/contact', validContactPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $message = (string) $response->json('message');
        expect(preg_match('/\p{Arabic}/u', $message))->toBe(1);
    });
});
