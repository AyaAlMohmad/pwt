<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('PATCH /api/v1/admin/users/{id} (happy paths)', function (): void {
    it('updates name and email for super_admin and writes an activity log entry', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", [
                'name' => 'New Name',
                'email' => 'new@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'admin.users')
            ->where('event', 'admin.user.updated')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($target->id)
            ->and($entry->properties['before']['name'] ?? null)->toBe('Old Name')
            ->and($entry->properties['after']['name'] ?? null)->toBe('New Name')
            ->and($entry->properties['before']['email'] ?? null)->toBe('old@example.com')
            ->and($entry->properties['after']['email'] ?? null)->toBe('new@example.com');
    });

    it('changes a customer role to sales and writes a role_changed activity entry with before/after', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", [
                'role' => UserRole::Sales->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.role', UserRole::Sales->value);

        $target->refresh();
        expect($target->hasRole(UserRole::Sales->value))->toBeTrue();
        expect($target->hasRole(UserRole::Customer->value))->toBeFalse();

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'admin.users')
            ->where('event', 'admin.user.role_changed')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($target->id)
            ->and($entry->properties['before_role'] ?? null)->toBe(UserRole::Customer->value)
            ->and($entry->properties['after_role'] ?? null)->toBe(UserRole::Sales->value);
    });

    it('revokes ALL tokens when is_active is toggled false', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $target = User::factory()->customer()->create();
        $target->createToken('mobile-1');
        $target->createToken('mobile-2');
        expect($target->tokens()->count())->toBe(2);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $target->refresh();
        expect($target->is_active)->toBeFalse();
        expect($target->tokens()->count())->toBe(0);
    });
});

describe('PATCH /api/v1/admin/users/{id} (authorization)', function (): void {
    it('returns 403 when a non-super_admin (operations) attempts ANY update', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'NewName'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 when a support-role admin attempts to update', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'NewName'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for a customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $plain = $customer->createToken('mobile')->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', "Bearer $plain")
            ->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'NewName'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});

describe('PATCH /api/v1/admin/users/{id} (validation + business rules)', function (): void {
    it('returns 422 when the body is empty', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['body']]);
    });

    it('returns 422 for invalid email', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['email']]);
    });

    it('returns 422 when the email is already taken by a different user', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $other = User::factory()->customer()->create(['email' => 'taken@example.com']);
        $target = User::factory()->customer()->create(['email' => 'mine@example.com']);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['email']]);
    });

    it('allows setting the email to the user current value (excludes self from uniqueness)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create(['email' => 'mine@example.com']);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", [
                'email' => 'mine@example.com',
                'name' => 'Updated',
            ])
            ->assertOk();
    });

    it('returns 422 ERR_CANNOT_CHANGE_OWN_ROLE when caller updates their own role', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$admin->id}", [
                'role' => UserRole::Sales->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_CANNOT_CHANGE_OWN_ROLE');

        $admin->refresh();
        expect($admin->hasRole(UserRole::SuperAdmin->value))->toBeTrue();
    });

    it('returns 422 ERR_CANNOT_DEACTIVATE_SELF when caller sets is_active=false on themselves', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        // A second super_admin exists so the last-super-admin guard doesn't fire.
        User::factory()->admin(UserRole::SuperAdmin->value)->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_CANNOT_DEACTIVATE_SELF');

        $admin->refresh();
        expect($admin->is_active)->toBeTrue();
    });

    it('returns 422 ERR_LAST_SUPER_ADMIN when deactivating the only remaining super_admin', function (): void {
        // Structurally unreachable through the API surface: the
        // UserPolicy::updateAdmin defensive guard rejects any non-super_admin
        // modifying a super_admin target (403 ERR_FORBIDDEN), and any
        // super_admin caller is themselves counted as an "other active
        // super_admin" so the guard count never reaches zero. The
        // service-level guard is covered directly in
        // tests/Unit/Services/Admin/AdminUserServiceTest.php.
        expect(true)->toBeTrue();
    })->skip('See note: LastSuperAdmin via PATCH is unreachable end-to-end; covered by service unit test.');
});

describe('PATCH cross-role guards', function (): void {
    it('returns 403 when a non-super_admin attempts to update a super_admin target', function (): void {
        // Grant a sales admin users.update + users.deactivate (so they pass the
        // policy permission gate) but NOT super_admin — defensive guard fires.
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        $admin->givePermissionTo('users.update');
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $target = User::factory()->admin(UserRole::SuperAdmin->value)->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'NewName'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_FORBIDDEN when a non-super_admin sends a role change payload', function (): void {
        // Even with users.update granted, the changeRole policy demands
        // super_admin specifically — policy gate fires.
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        $admin->givePermissionTo('users.update');
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/users/{$target->id}", [
                'role' => UserRole::Support->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});
