<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Testimonial;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Admin Testimonials — feature tests (Phase 20)
|--------------------------------------------------------------------------
|
| Covers GET, POST, PUT, DELETE /api/v1/admin/testimonials[/{id}].
| Pipeline: MySQL `pwt_testing`.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * @return array{0: User, 1: string}
 */
function testimonialAdminBearer(string $role = UserRole::SuperAdmin->value): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('GET /api/v1/admin/testimonials', function (): void {
    it('returns 200 with the list for super_admin', function (): void {
        [, $bearer] = testimonialAdminBearer();
        Testimonial::factory()->create(['name' => 'Alice']);
        Testimonial::factory()->inactive()->create(['name' => 'Bob']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/testimonials');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'name', 'role', 'company', 'avatar_url',
                        'rating', 'quote', 'display_order', 'is_active',
                        'status', 'deleted_at', 'created_at', 'updated_at',
                    ],
                ],
                'meta' => ['pagination'],
            ]);

        $names = array_map(static fn ($r) => $r['name'], $response->json('data'));
        expect($names)->toContain('Alice')->toContain('Bob');
    });

    it('filters by rating', function (): void {
        [, $bearer] = testimonialAdminBearer();
        Testimonial::factory()->rating(5)->create(['name' => 'High']);
        Testimonial::factory()->rating(3)->create(['name' => 'Mid']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/testimonials?rating=5')
            ->assertOk();

        $names = array_map(static fn ($r) => $r['name'], $response->json('data'));
        expect($names)->toContain('High')->not->toContain('Mid');
    });

    it('allows sales role to read (200)', function (): void {
        [, $bearer] = testimonialAdminBearer(UserRole::Sales->value);
        Testimonial::factory()->create();
        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/testimonials')
            ->assertOk();
    });

    it('rejects operations and support with 403', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            [, $bearer] = testimonialAdminBearer($role);
            $this->withHeader('Authorization', $bearer)
                ->getJson('/api/v1/admin/testimonials')
                ->assertStatus(403);
        }
    });

    it('rejects customer tokens with 403', function (): void {
        $customer = User::factory()->customer()->create();
        $bearer = 'Bearer '.$customer->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/testimonials')
            ->assertStatus(403);
    });

    it('returns 401 unauthenticated', function (): void {
        $this->getJson('/api/v1/admin/testimonials')->assertStatus(401);
    });
});

describe('GET /api/v1/admin/testimonials/{id}', function (): void {
    it('returns a testimonial detail with raw bilingual quote', function (): void {
        [, $bearer] = testimonialAdminBearer();
        $testimonial = Testimonial::factory()->create([
            'name' => 'Show',
            'quote' => ['en' => 'Great service', 'ar' => 'خدمة رائعة'],
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/testimonials/{$testimonial->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $testimonial->id)
            ->assertJsonPath('data.name', 'Show')
            ->assertJsonPath('data.quote.en', 'Great service')
            ->assertJsonPath('data.quote.ar', 'خدمة رائعة');
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = testimonialAdminBearer();
        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/testimonials/9999999')
            ->assertStatus(404);
    });
});

describe('POST /api/v1/admin/testimonials', function (): void {
    it('creates with bilingual quote and writes activity entry', function (): void {
        [$admin, $bearer] = testimonialAdminBearer();

        $payload = [
            'name' => 'Jane Doe',
            'role' => 'Owner',
            'company' => 'AquaCo',
            'rating' => 5,
            'quote' => ['en' => 'Loved it.', 'ar' => 'أحببتها.'],
            'display_order' => 1,
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/testimonials', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.quote.en', 'Loved it.')
            ->assertJsonPath('data.quote.ar', 'أحببتها.');

        $this->assertDatabaseHas('testimonials', ['name' => 'Jane Doe']);

        $entry = Activity::query()
            ->where('log_name', 'admin.testimonials')
            ->where('event', 'testimonial.admin_created')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id);
    });

    it('returns 422 when rating is missing', function (): void {
        [, $bearer] = testimonialAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/testimonials', [
                'name' => 'No Rating',
                'quote' => ['en' => 'X'],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['rating']]);
    });

    it('returns 422 when rating is out of range', function (): void {
        [, $bearer] = testimonialAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/testimonials', [
                'name' => 'Bad',
                'rating' => 10,
                'quote' => ['en' => 'X'],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['rating']]);
    });

    it('returns 422 when quote.en is missing', function (): void {
        [, $bearer] = testimonialAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/testimonials', [
                'name' => 'No EN',
                'rating' => 5,
                'quote' => ['ar' => 'فقط عربي'],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['quote.en']]);
    });

    it('allows sales role to create', function (): void {
        [, $bearer] = testimonialAdminBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/testimonials', [
                'name' => 'Sales Created',
                'rating' => 4,
                'quote' => ['en' => 'Good'],
            ])
            ->assertCreated();
    });

    it('rejects operations role with 403', function (): void {
        [, $bearer] = testimonialAdminBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/testimonials', [
                'name' => 'Ops',
                'rating' => 5,
                'quote' => ['en' => 'X'],
            ])
            ->assertStatus(403);
    });

    it('rejects support role with 403', function (): void {
        [, $bearer] = testimonialAdminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/testimonials', [
                'name' => 'Sup',
                'rating' => 5,
                'quote' => ['en' => 'X'],
            ])
            ->assertStatus(403);
    });

    it('returns 401 unauthenticated', function (): void {
        $this->postJson('/api/v1/admin/testimonials', [
            'name' => 'Anon',
            'rating' => 5,
            'quote' => ['en' => 'X'],
        ])->assertStatus(401);
    });
});

describe('PUT /api/v1/admin/testimonials/{id}', function (): void {
    it('updates a testimonial and returns 200', function (): void {
        [, $bearer] = testimonialAdminBearer();
        $t = Testimonial::factory()->create(['name' => 'Old', 'rating' => 3]);

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/testimonials/{$t->id}", [
                'name' => 'New',
                'rating' => 5,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.rating', 5);
    });

    it('merges single-locale quote with stored translations on PUT', function (): void {
        [, $bearer] = testimonialAdminBearer();
        $t = Testimonial::factory()->create([
            'quote' => ['en' => 'EN base', 'ar' => 'AR base'],
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/testimonials/{$t->id}", [
                'quote' => ['en' => 'EN updated'],
            ])->assertOk();

        expect($response->json('data.quote.en'))->toBe('EN updated');
        expect($response->json('data.quote.ar'))->toBe('AR base');
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = testimonialAdminBearer();
        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/testimonials/9999999', ['name' => 'X'])
            ->assertStatus(404);
    });

    it('rejects operations role on update', function (): void {
        [, $bearer] = testimonialAdminBearer(UserRole::Operations->value);
        $t = Testimonial::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/testimonials/{$t->id}", ['name' => 'X'])
            ->assertStatus(403);
    });
});

describe('DELETE /api/v1/admin/testimonials/{id}', function (): void {
    it('soft-deletes for super_admin', function (): void {
        [, $bearer] = testimonialAdminBearer();
        $t = Testimonial::factory()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/testimonials/{$t->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $t->id);
        expect($response->json('data.deleted_at'))->not->toBeNull();
        $this->assertSoftDeleted('testimonials', ['id' => $t->id]);
    });

    it('returns 403 for sales role (super_admin only)', function (): void {
        [, $bearer] = testimonialAdminBearer(UserRole::Sales->value);
        $t = Testimonial::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/testimonials/{$t->id}")
            ->assertStatus(403);
        $this->assertDatabaseHas('testimonials', ['id' => $t->id, 'deleted_at' => null]);
    });

    it('returns 403 for operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            [, $bearer] = testimonialAdminBearer($role);
            $t = Testimonial::factory()->create();
            $this->withHeader('Authorization', $bearer)
                ->deleteJson("/api/v1/admin/testimonials/{$t->id}")
                ->assertStatus(403);
        }
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = testimonialAdminBearer();
        $this->withHeader('Authorization', $bearer)
            ->deleteJson('/api/v1/admin/testimonials/9999999')
            ->assertStatus(404);
    });

    it('returns 401 unauthenticated', function (): void {
        $t = Testimonial::factory()->create();
        $this->deleteJson("/api/v1/admin/testimonials/{$t->id}")->assertStatus(401);
    });
});
