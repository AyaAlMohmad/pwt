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

describe('DELETE /api/v1/admin/users/{id} (happy path)', function (): void {
    it('soft-deletes a customer, revokes their tokens, and writes a deactivated activity log entry', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $target = User::factory()->customer()->create();
        $target->createToken('mobile-1');
        $target->createToken('mobile-2');
        expect($target->tokens()->count())->toBe(2);

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/users/{$target->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonStructure(['data' => ['id', 'is_active', 'deleted_at', 'counts']]);

        expect($response->json('data.deleted_at'))->not->toBeNull();

        $target->refresh();
        expect($target->is_active)->toBeFalse()
            ->and($target->deleted_at)->not->toBeNull()
            ->and($target->tokens()->count())->toBe(0);

        $this->assertSoftDeleted('users', ['id' => $target->id]);

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'admin.users')
            ->where('event', 'admin.user.deactivated')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($target->id)
            ->and($entry->properties['no_op'] ?? null)->toBeFalse()
            ->and($entry->properties['tokens_revoked'] ?? null)->toBe(2);
    });
});

describe('DELETE /api/v1/admin/users/{id} (business rules)', function (): void {
    it('returns 422 ERR_CANNOT_DEACTIVATE_SELF when caller deletes their own row', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        // Another super_admin so the last-super-admin guard does not fire.
        User::factory()->admin(UserRole::SuperAdmin->value)->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_CANNOT_DEACTIVATE_SELF');

        $admin->refresh();
        expect($admin->trashed())->toBeFalse();
    });

    it('returns 422 ERR_LAST_SUPER_ADMIN when deactivating the only remaining super_admin', function (): void {
        // Structurally unreachable end-to-end: the UserPolicy::deactivate
        // defensive guard rejects any non-super_admin caller targeting a
        // super_admin (403 ERR_FORBIDDEN), and a super_admin caller is
        // themselves counted as another active super_admin so the guard
        // count never reaches zero. Service-level guard is covered by the
        // unit test in tests/Unit/Services/Admin/AdminUserServiceTest.php.
        expect(true)->toBeTrue();
    })->skip('See note: LastSuperAdmin via DELETE is unreachable end-to-end; covered by service unit test.');

    it('is idempotent for an already soft-deleted user (200 with no_op activity entry)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $target = User::factory()->customer()->inactive()->create();
        $target->delete();
        $deletedAt = $target->fresh()->deleted_at;

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/users/{$target->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.is_active', false);

        // deleted_at should be unchanged (idempotent — does NOT re-stamp).
        $target->refresh();
        expect($target->deleted_at)->not->toBeNull();
        expect($target->deleted_at->equalTo($deletedAt))->toBeTrue();

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'admin.users')
            ->where('event', 'admin.user.deactivated')
            ->where('subject_id', $target->id)
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->properties['no_op'] ?? null)->toBeTrue()
            ->and($entry->properties['was_already_deleted'] ?? null)->toBeTrue();
    });
});

describe('DELETE /api/v1/admin/users/{id} (authorization)', function (): void {
    it('returns 403 for support role', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');

        $target->refresh();
        expect($target->trashed())->toBeFalse();
    });

    it('returns 403 for operations role', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for sales role', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', $bearer)
            ->deleteJson('/api/v1/admin/users/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});
