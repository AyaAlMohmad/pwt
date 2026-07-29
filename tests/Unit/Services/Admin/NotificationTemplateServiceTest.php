<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Admin\NotificationTemplateService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| NotificationTemplateService — unit
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md
| § 3.2 / § 3.3 / § 6.6.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(NotificationTemplateService::class);
});

function svcTemplate(array $overrides = []): NotificationTemplate
{
    return NotificationTemplate::factory()
        ->state(array_merge([
            'key' => 'order.confirmed',
            'channel' => NotificationChannel::InApp->value,
        ], $overrides))
        ->create();
}

describe('NotificationTemplateService::list()', function (): void {
    it('paginates all rows by default', function (): void {
        svcTemplate();
        svcTemplate(['key' => 'order.cancelled', 'channel' => NotificationChannel::Sms->value]);

        $paginator = $this->service->list([], 1, 15);
        expect($paginator->total())->toBe(2);
    });

    it('filters by channel', function (): void {
        svcTemplate(['channel' => NotificationChannel::InApp->value]);
        svcTemplate(['key' => 'order.cancelled', 'channel' => NotificationChannel::Sms->value]);

        $paginator = $this->service->list(['channel' => 'sms'], 1, 15);
        expect($paginator->total())->toBe(1)
            ->and($paginator->items()[0]->channel->value)->toBe('sms');
    });

    it('filters by key substring', function (): void {
        svcTemplate(['key' => 'order.confirmed']);
        svcTemplate(['key' => 'maintenance.scheduled', 'channel' => NotificationChannel::Email->value]);

        expect($this->service->list(['key' => 'order'], 1, 15)->total())->toBe(1)
            ->and($this->service->list(['key' => 'maintenance'], 1, 15)->total())->toBe(1);
    });

    it('filters by is_active boolean', function (): void {
        svcTemplate(['is_active' => true]);
        svcTemplate(['key' => 'order.cancelled', 'is_active' => false]);

        expect($this->service->list(['is_active' => false], 1, 15)->total())->toBe(1)
            ->and($this->service->list(['is_active' => true], 1, 15)->total())->toBe(1);
    });

    it('sorts by `key` ascending by default', function (): void {
        svcTemplate(['key' => 'zzz.last', 'channel' => NotificationChannel::Email->value]);
        svcTemplate(['key' => 'aaa.first']);

        $items = $this->service->list([], 1, 15)->items();
        expect($items[0]->key)->toBe('aaa.first')
            ->and($items[1]->key)->toBe('zzz.last');
    });

    it('supports sort=updated_at order=desc', function (): void {
        $older = svcTemplate(['key' => 'older', 'updated_at' => now()->subDay()]);
        $newer = svcTemplate(['key' => 'newer', 'channel' => NotificationChannel::Email->value, 'updated_at' => now()]);

        $items = $this->service->list(['sort' => 'updated_at', 'order' => 'desc'], 1, 15)->items();
        expect($items[0]->id)->toBe($newer->id)
            ->and($items[1]->id)->toBe($older->id);
    });

    it('falls back to default sort when sort param is unknown', function (): void {
        svcTemplate(['key' => 'aaa']);
        svcTemplate(['key' => 'zzz', 'channel' => NotificationChannel::Sms->value]);

        $items = $this->service->list(['sort' => 'something-bogus'], 1, 15)->items();
        expect($items[0]->key)->toBe('aaa');
    });
});

describe('NotificationTemplateService::find()', function (): void {
    it('returns the row when it exists', function (): void {
        $template = svcTemplate();

        expect($this->service->find($template->id)->id)->toBe($template->id);
    });

    it('throws ModelNotFoundException for an unknown id', function (): void {
        $this->service->find(999999);
    })->throws(ModelNotFoundException::class);
});

describe('NotificationTemplateService::update()', function (): void {
    it('updates both locales for subject + body and flips is_active', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $template = svcTemplate([
            'subject' => ['en' => 'Old EN', 'ar' => 'الموضوع القديم'],
            'body' => ['en' => 'Old body EN', 'ar' => 'نص قديم'],
            'is_active' => true,
        ]);

        $updated = $this->service->update($template, [
            'subject' => ['en' => 'New EN', 'ar' => 'موضوع جديد'],
            'body' => ['en' => 'New body EN', 'ar' => 'نص جديد'],
            'is_active' => false,
        ], $admin);

        expect($updated->getTranslation('subject', 'en'))->toBe('New EN')
            ->and($updated->getTranslation('subject', 'ar'))->toBe('موضوع جديد')
            ->and($updated->getTranslation('body', 'en'))->toBe('New body EN')
            ->and($updated->getTranslation('body', 'ar'))->toBe('نص جديد')
            ->and($updated->is_active)->toBeFalse();
    });

    it('preserves immutable key / channel / placeholders', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $template = svcTemplate([
            'key' => 'order.confirmed',
            'channel' => NotificationChannel::InApp->value,
            'placeholders' => ['user.name'],
        ]);

        $this->service->update($template, [
            'subject' => ['en' => 'EN', 'ar' => 'AR'],
            'body' => ['en' => 'EN body', 'ar' => 'AR body'],
            'is_active' => true,
        ], $admin);

        $template->refresh();
        expect($template->key)->toBe('order.confirmed')
            ->and($template->channel->value)->toBe(NotificationChannel::InApp->value)
            ->and($template->placeholders)->toBe(['user.name']);
    });

    it('writes one activity_log entry with before/after diff', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $template = svcTemplate([
            'subject' => ['en' => 'Old EN', 'ar' => 'الموضوع القديم'],
            'body' => ['en' => 'Old body', 'ar' => 'نص'],
            'is_active' => true,
        ]);

        $this->service->update($template, [
            'subject' => ['en' => 'New EN', 'ar' => 'جديد'],
            'body' => ['en' => 'New body', 'ar' => 'جديد'],
            'is_active' => false,
        ], $admin);

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'admin.notification_templates')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->description)->toBe('notification_template.updated')
            ->and($entry->event)->toBe('updated')
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($template->id)
            ->and($entry->properties['old']['subject']['en'] ?? null)->toBe('Old EN')
            ->and($entry->properties['new']['subject']['en'] ?? null)->toBe('New EN')
            ->and($entry->properties['old']['body']['ar'] ?? null)->toBe('نص')
            ->and($entry->properties['old']['is_active'] ?? null)->toBeTrue()
            ->and($entry->properties['new']['is_active'] ?? null)->toBeFalse();
    });

    it('runs the update + log inside a transaction (DB row + activity persisted together)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $template = svcTemplate();

        $beforeCount = Activity::query()->count();

        $this->service->update($template, [
            'subject' => ['en' => 'EN', 'ar' => 'AR'],
            'body' => ['en' => 'EN body', 'ar' => 'AR body'],
            'is_active' => true,
        ], $admin);

        expect(Activity::query()->count())->toBe($beforeCount + 1);
    });
});
