<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\Admin\Users\CannotChangeOwnRoleException;
use App\Exceptions\Admin\Users\CannotDeactivateSelfException;
use App\Exceptions\Admin\Users\LastSuperAdminException;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AdminUserService::class);
});

describe('AdminUserService::list()', function (): void {
    it('applies role filter and excludes other roles', function (): void {
        User::factory()->count(3)->customer()->create();
        User::factory()->admin(UserRole::Support->value)->create();

        $paginator = $this->service->list(['role' => 'customer'], 1, 50);

        expect($paginator->total())->toBe(3);
        foreach ($paginator->items() as $user) {
            expect($user->hasRole(UserRole::Customer->value))->toBeTrue();
        }
    });

    it('filters by is_active=false', function (): void {
        User::factory()->customer()->create(['is_active' => true]);
        $inactive = User::factory()->customer()->inactive()->create();

        $paginator = $this->service->list(['is_active' => false], 1, 50);

        expect($paginator->total())->toBe(1);
        expect($paginator->items()[0]->id)->toBe($inactive->id);
    });

    it('matches search across name/email/phone', function (): void {
        User::factory()->customer()->create(['name' => 'NeedleName']);
        User::factory()->customer()->create(['email' => 'foo@example.com']);
        User::factory()->customer()->create();

        expect($this->service->list(['search' => 'NeedleName'], 1, 50)->total())->toBe(1);
        expect($this->service->list(['search' => 'foo@example'], 1, 50)->total())->toBe(1);
    });

    it('excludes soft-deleted users by default and includes them when include_trashed=true', function (): void {
        $alive = User::factory()->customer()->create();
        $dead = User::factory()->customer()->create();
        $dead->delete();

        $default = $this->service->list([], 1, 50);
        expect($default->total())->toBe(1);
        expect($default->items()[0]->id)->toBe($alive->id);

        $withTrashed = $this->service->list(['include_trashed' => true], 1, 50);
        expect($withTrashed->total())->toBe(2);
    });

    it('honours sort/order whitelist and defaults to created_at desc', function (): void {
        User::factory()->customer()->create(['name' => 'A First']);
        User::factory()->customer()->create(['name' => 'B Second']);

        $asc = $this->service->list(['sort' => 'name', 'order' => 'asc'], 1, 50);
        $names = array_map(static fn (User $u) => $u->name, $asc->items());
        $sorted = $names;
        sort($sorted);
        expect($names)->toBe($sorted);

        // Unknown sort falls back to created_at.
        $fallback = $this->service->list(['sort' => 'bogus_column'], 1, 50);
        expect($fallback->total())->toBe(2);
    });
});

describe('AdminUserService::find()', function (): void {
    it('returns a soft-deleted user when includeTrashed is true (default)', function (): void {
        $user = User::factory()->customer()->create();
        $user->delete();

        $found = $this->service->find($user->id);
        expect($found->id)->toBe($user->id)
            ->and($found->trashed())->toBeTrue();
    });

    it('throws ModelNotFoundException when includeTrashed=false on a soft-deleted user', function (): void {
        $user = User::factory()->customer()->create();
        $user->delete();

        $this->service->find($user->id, includeTrashed: false);
    })->throws(ModelNotFoundException::class);

    it('loads aggregate counts on the returned model', function (): void {
        $user = User::factory()->customer()->create();
        $found = $this->service->find($user->id);

        expect($found->getAttribute('orders_count'))->toBe(0)
            ->and($found->getAttribute('support_tickets_count'))->toBe(0)
            ->and($found->getAttribute('machines_count'))->toBe(0);
    });
});

describe('AdminUserService::update()', function (): void {
    it('throws CannotChangeOwnRoleException when actor === target and role present', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $this->service->update($actor, ['role' => UserRole::Sales->value], $actor);
    })->throws(CannotChangeOwnRoleException::class);

    it('throws CannotDeactivateSelfException when actor === target and is_active=false', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        User::factory()->admin(UserRole::SuperAdmin->value)->create(); // another super_admin

        $this->service->update($actor, ['is_active' => false], $actor);
    })->throws(CannotDeactivateSelfException::class);

    it('changes role, syncs Spatie roles, and writes role_changed activity', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->create();

        $updated = $this->service->update($target, ['role' => UserRole::Support->value], $actor);

        expect($updated->hasRole(UserRole::Support->value))->toBeTrue()
            ->and($updated->hasRole(UserRole::Customer->value))->toBeFalse();

        $entry = Activity::query()
            ->where('log_name', 'admin.users')
            ->where('event', 'admin.user.role_changed')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['before_role'])->toBe('customer')
            ->and($entry->properties['after_role'])->toBe(UserRole::Support->value);
    });

    it('revokes tokens and emits activity_toggled when is_active flips true->false', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->create();
        $target->createToken('t1');
        $target->createToken('t2');
        expect($target->tokens()->count())->toBe(2);

        $updated = $this->service->update($target, ['is_active' => false], $actor);
        expect($updated->is_active)->toBeFalse()
            ->and($updated->tokens()->count())->toBe(0);

        $entry = Activity::query()
            ->where('event', 'admin.user.activity_toggled')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['before_is_active'])->toBeTrue()
            ->and($entry->properties['after_is_active'])->toBeFalse();
    });

    it('does NOT emit activity when nothing actually changes', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->create(['name' => 'Same']);

        Activity::query()->delete();
        $this->service->update($target, ['name' => 'Same'], $actor);

        expect(Activity::query()->where('log_name', 'admin.users')->count())->toBe(0);
    });

    it('fires LastSuperAdminException when demoting the only remaining super_admin', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->admin(UserRole::SuperAdmin->value)->create();

        // Remove actor from super_admin so target is the SOLE remaining one.
        $actor->syncRoles([UserRole::Sales->value]);

        $this->service->update($target, ['role' => UserRole::Sales->value], $actor);
    })->throws(LastSuperAdminException::class);

    it('fires LastSuperAdminException when deactivating the only remaining super_admin via PATCH', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $actor->syncRoles([UserRole::Sales->value]);

        $this->service->update($target, ['is_active' => false], $actor);
    })->throws(LastSuperAdminException::class);
});

describe('AdminUserService::deactivate()', function (): void {
    it('fires CannotDeactivateSelfException when actor === target', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();

        $this->service->deactivate($actor, $actor);
    })->throws(CannotDeactivateSelfException::class);

    it('fires LastSuperAdminException when target is the sole super_admin', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $actor->syncRoles([UserRole::Sales->value]);

        $this->service->deactivate($target, $actor);
    })->throws(LastSuperAdminException::class);

    it('soft-deletes, revokes tokens, and writes activity on a normal target', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->create();
        $target->createToken('t1');
        $target->createToken('t2');

        $result = $this->service->deactivate($target, $actor, '127.0.0.1');

        expect($result->trashed())->toBeTrue()
            ->and($result->is_active)->toBeFalse()
            ->and($result->tokens()->count())->toBe(0);

        $entry = Activity::query()
            ->where('log_name', 'admin.users')
            ->where('event', 'admin.user.deactivated')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($actor->id)
            ->and($entry->subject_id)->toBe($target->id)
            ->and($entry->properties['tokens_revoked'])->toBe(2)
            ->and($entry->properties['no_op'])->toBeFalse()
            ->and($entry->properties['ip'])->toBe('127.0.0.1');
    });

    it('is idempotent on an already soft-deleted user (no_op activity)', function (): void {
        $actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->inactive()->create();
        $target->delete();
        $deletedAt = $target->fresh()->deleted_at;

        $result = $this->service->deactivate($target, $actor);

        expect($result->id)->toBe($target->id);
        $target->refresh();
        expect($target->deleted_at->equalTo($deletedAt))->toBeTrue();

        $entry = Activity::query()
            ->where('event', 'admin.user.deactivated')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['no_op'])->toBeTrue()
            ->and($entry->properties['was_already_deleted'])->toBeTrue();
    });
});
