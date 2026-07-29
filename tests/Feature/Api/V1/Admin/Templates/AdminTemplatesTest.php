<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\NotificationTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| /api/v1/admin/notification-templates
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md
| § 4.2 / § 4.3 / § 6.2.2 / § 6.6 / § 9.4.
|
| super_admin only — operations / sales / support → 403 on every action.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function tmplBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function makeTemplate(array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::factory()
        ->state(array_merge([
            'key' => 'order.confirmed',
            'channel' => NotificationChannel::InApp->value,
        ], $overrides))
        ->create();
}

describe('GET /admin/notification-templates — list authorization', function (): void {
    it('returns 200 for super_admin', function (): void {
        [, $bearer] = tmplBearer(UserRole::SuperAdmin->value);
        makeTemplate();
        makeTemplate(['key' => 'order.cancelled', 'channel' => NotificationChannel::Sms->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'key',
                        'channel',
                        'subject' => ['en', 'ar'],
                        'body' => ['en', 'ar'],
                        'placeholders',
                        'is_active',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total']],
            ]);

        expect($response->json('meta.pagination.total'))->toBe(2)
            ->and($response->json('meta.pagination.per_page'))->toBe(15);
    });

    it('returns 403 for operations', function (): void {
        [, $bearer] = tmplBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for sales', function (): void {
        [, $bearer] = tmplBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for support', function (): void {
        [, $bearer] = tmplBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for a customer token (admin middleware)', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/notification-templates')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/notification-templates')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /admin/notification-templates — filtering + bilingual shape', function (): void {
    it('filters by channel', function (): void {
        [, $bearer] = tmplBearer();
        makeTemplate(['channel' => NotificationChannel::InApp->value]);
        makeTemplate(['key' => 'order.cancelled', 'channel' => NotificationChannel::Sms->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates?channel=sms')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.channel'))->toBe('sms');
    });

    it('filters by key substring (case-insensitive)', function (): void {
        [, $bearer] = tmplBearer();
        makeTemplate(['key' => 'order.confirmed']);
        makeTemplate(['key' => 'maintenance.scheduled', 'channel' => NotificationChannel::Email->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates?key=order')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.key'))->toBe('order.confirmed');
    });

    it('filters by is_active=false', function (): void {
        [, $bearer] = tmplBearer();
        makeTemplate();
        makeTemplate(['key' => 'order.cancelled', 'is_active' => false]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates?is_active=0')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.is_active'))->toBeFalse();
    });

    it('returns BOTH locales (en + ar) for subject and body', function (): void {
        [, $bearer] = tmplBearer();
        makeTemplate([
            'subject' => ['en' => 'Subject EN', 'ar' => 'الموضوع'],
            'body' => ['en' => 'Body EN', 'ar' => 'النص'],
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertOk();

        expect($response->json('data.0.subject.en'))->toBe('Subject EN')
            ->and($response->json('data.0.subject.ar'))->toBe('الموضوع')
            ->and($response->json('data.0.body.en'))->toBe('Body EN')
            ->and($response->json('data.0.body.ar'))->toBe('النص');
    });

    it('rejects invalid channel with 422', function (): void {
        [, $bearer] = tmplBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/notification-templates?channel=carrier-pigeon')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['channel']]);
    });
});

describe('PUT /admin/notification-templates/{id} — authorization', function (): void {
    it('updates bilingual subject + body + is_active for super_admin', function (): void {
        [$admin, $bearer] = tmplBearer(UserRole::SuperAdmin->value);
        $template = makeTemplate([
            'subject' => ['en' => 'Old Subject', 'ar' => 'الموضوع القديم'],
            'body' => ['en' => 'Old body', 'ar' => 'نص قديم'],
            'is_active' => true,
        ]);

        $payload = [
            'subject' => ['en' => 'New Subject', 'ar' => 'موضوع جديد'],
            'body' => ['en' => 'Hello {{user.name}}', 'ar' => 'مرحبا {{user.name}}'],
            'is_active' => false,
        ];

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, $payload)
            ->assertOk();

        expect($response->json('data.subject.en'))->toBe('New Subject')
            ->and($response->json('data.subject.ar'))->toBe('موضوع جديد')
            ->and($response->json('data.body.en'))->toBe('Hello {{user.name}}')
            ->and($response->json('data.is_active'))->toBeFalse();

        $template->refresh();
        expect($template->getTranslation('subject', 'en'))->toBe('New Subject')
            ->and($template->getTranslation('subject', 'ar'))->toBe('موضوع جديد')
            ->and($template->is_active)->toBeFalse();

        unset($admin);
    });

    it('writes one activity_log entry with before/after diff', function (): void {
        [$admin, $bearer] = tmplBearer();
        $template = makeTemplate([
            'subject' => ['en' => 'Old Subject', 'ar' => 'الموضوع القديم'],
            'body' => ['en' => 'Old body', 'ar' => 'نص قديم'],
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, [
                'subject' => ['en' => 'New Subject', 'ar' => 'موضوع جديد'],
                'body' => ['en' => 'New body', 'ar' => 'نص جديد'],
                'is_active' => false,
            ])
            ->assertOk();

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'admin.notification_templates')
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($template->id)
            ->and($entry->properties['old']['subject']['en'] ?? null)->toBe('Old Subject')
            ->and($entry->properties['new']['subject']['en'] ?? null)->toBe('New Subject')
            ->and($entry->properties['old']['is_active'] ?? null)->toBeTrue()
            ->and($entry->properties['new']['is_active'] ?? null)->toBeFalse();
    });

    it('returns 403 for operations', function (): void {
        [, $bearer] = tmplBearer(UserRole::Operations->value);
        $template = makeTemplate();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, [
                'subject' => ['en' => 'New', 'ar' => 'جديد'],
                'body' => ['en' => 'New body', 'ar' => 'نص جديد'],
                'is_active' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for sales', function (): void {
        [, $bearer] = tmplBearer(UserRole::Sales->value);
        $template = makeTemplate();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, [
                'subject' => ['en' => 'New', 'ar' => 'جديد'],
                'body' => ['en' => 'New body', 'ar' => 'نص جديد'],
                'is_active' => true,
            ])
            ->assertStatus(403);
    });

    it('returns 403 for support', function (): void {
        [, $bearer] = tmplBearer(UserRole::Support->value);
        $template = makeTemplate();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, [
                'subject' => ['en' => 'New', 'ar' => 'جديد'],
                'body' => ['en' => 'New body', 'ar' => 'نص جديد'],
                'is_active' => true,
            ])
            ->assertStatus(403);
    });

    it('returns 404 when the template id does not exist', function (): void {
        [, $bearer] = tmplBearer();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/999999', [
                'subject' => ['en' => 'New', 'ar' => 'جديد'],
                'body' => ['en' => 'New body', 'ar' => 'نص جديد'],
                'is_active' => true,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 422 when subject.ar is missing', function (): void {
        [, $bearer] = tmplBearer();
        $template = makeTemplate();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, [
                'subject' => ['en' => 'Only English'],
                'body' => ['en' => 'New body', 'ar' => 'نص جديد'],
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['subject.ar']]);
    });

    it('returns 422 when body.en is missing', function (): void {
        [, $bearer] = tmplBearer();
        $template = makeTemplate();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, [
                'subject' => ['en' => 'EN', 'ar' => 'AR'],
                'body' => ['ar' => 'نص جديد'],
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['body.en']]);
    });

    it('returns 401 without a token', function (): void {
        $template = makeTemplate();

        $this->putJson('/api/v1/admin/notification-templates/'.$template->id, [
            'subject' => ['en' => 'EN', 'ar' => 'AR'],
            'body' => ['en' => 'EN', 'ar' => 'AR'],
            'is_active' => true,
        ])->assertStatus(401);
    });

    it('silently ignores read-only key / channel / placeholders in the payload', function (): void {
        [, $bearer] = tmplBearer();
        $template = makeTemplate([
            'key' => 'order.confirmed',
            'channel' => NotificationChannel::InApp->value,
            'placeholders' => ['user.name'],
        ]);

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/notification-templates/'.$template->id, [
                'key' => 'totally.different',
                'channel' => NotificationChannel::Sms->value,
                'placeholders' => ['some.other.thing'],
                'subject' => ['en' => 'EN', 'ar' => 'AR'],
                'body' => ['en' => 'Body EN', 'ar' => 'Body AR'],
                'is_active' => true,
            ])
            ->assertOk();

        $template->refresh();
        expect($template->key)->toBe('order.confirmed')
            ->and($template->channel->value)->toBe(NotificationChannel::InApp->value)
            ->and($template->placeholders)->toBe(['user.name']);
    });
});
