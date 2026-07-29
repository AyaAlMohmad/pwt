<?php

declare(strict_types=1);

use App\Enums\ContactLeadSource;
use App\Models\ContactLead;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ContactLead model — relations, casts, factory states.
|--------------------------------------------------------------------------
|
| Contract: app/Models/ContactLead.php + docs/architecture/contact.md
| § 8.2 / § 8.3. The model is intentionally thin (no domain methods,
| no soft deletes, no translatable columns).
*/

beforeEach(function (): void {
    // Required so User::factory()->admin() can attach the admin role.
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('ContactLead relations', function (): void {
    it('belongsTo processedBy when processed_by_user_id is set', function (): void {
        $admin = User::factory()->admin()->create();
        $lead = ContactLead::factory()->processed($admin)->create();

        expect($lead->processedBy)->not->toBeNull();
        expect($lead->processedBy->id)->toBe($admin->id);
    });

    it('returns null for processedBy on a freshly-submitted unprocessed lead', function (): void {
        $lead = ContactLead::factory()->create();

        expect($lead->processed_by_user_id)->toBeNull();
        expect($lead->processedBy)->toBeNull();
    });
});

describe('ContactLead casts', function (): void {
    it('casts source to the ContactLeadSource enum', function (): void {
        $lead = ContactLead::factory()->mobile()->create();

        expect($lead->source)->toBeInstanceOf(ContactLeadSource::class);
        expect($lead->source)->toBe(ContactLeadSource::Mobile);
    });

    it('casts processed to a bool', function (): void {
        $lead = ContactLead::factory()->create();

        expect($lead->processed)->toBeBool();
        expect($lead->processed)->toBeFalse();
    });

    it('casts processed_at to a Carbon datetime when set', function (): void {
        $admin = User::factory()->admin()->create();
        $lead = ContactLead::factory()->processed($admin)->create();

        expect($lead->processed_at)->toBeInstanceOf(Carbon::class);
    });
});

describe('ContactLead factory states', function (): void {
    it('default factory state produces an unprocessed web-source lead', function (): void {
        $lead = ContactLead::factory()->create();

        expect($lead->processed)->toBeFalse();
        expect($lead->processed_by_user_id)->toBeNull();
        expect($lead->processed_at)->toBeNull();
        expect($lead->source)->toBe(ContactLeadSource::Web);
    });

    it('mobile() state flips source to mobile', function (): void {
        $lead = ContactLead::factory()->mobile()->create();

        expect($lead->source)->toBe(ContactLeadSource::Mobile);
    });

    it('withPhone() state populates phone + phone_country_code', function (): void {
        $lead = ContactLead::factory()->withPhone()->create();

        expect($lead->phone)->toBe('+971501234567');
        expect($lead->phone_country_code)->toBe('+971');
    });

    it('processed() state marks lead processed, links the admin, and stamps processed_at', function (): void {
        $admin = User::factory()->admin()->create();
        $lead = ContactLead::factory()->processed($admin)->create();

        expect($lead->processed)->toBeTrue();
        expect($lead->processed_by_user_id)->toBe($admin->id);
        expect($lead->processed_at)->not->toBeNull();
    });

    it('processed() state without an admin argument auto-creates a User', function (): void {
        $lead = ContactLead::factory()->processed()->create();

        expect($lead->processed)->toBeTrue();
        expect($lead->processed_by_user_id)->not->toBeNull();
        expect($lead->processedBy)->not->toBeNull();
    });
});

describe('ContactLead fillable attributes', function (): void {
    it('exposes the expected fillable allowlist', function (): void {
        $lead = new ContactLead;

        $expected = [
            'name', 'email', 'phone', 'phone_country_code',
            'subject', 'message', 'source',
            'ip', 'user_agent',
            'processed', 'processed_by_user_id', 'processed_at',
        ];

        foreach ($expected as $field) {
            expect($lead->getFillable())->toContain($field);
        }
    });
});
