<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new UserPolicy;
});

describe('UserPolicy::viewAny()', function (): void {
    it('allows admins with users.view (super_admin)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->viewAny($admin))->toBeTrue();
    });

    it('allows support (granted users.view)', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        expect($this->policy->viewAny($admin))->toBeTrue();
    });

    it('denies operations (no users.view)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        expect($this->policy->viewAny($admin))->toBeFalse();
    });

    it('denies sales (no users.view)', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        expect($this->policy->viewAny($admin))->toBeFalse();
    });

    it('denies plain customers', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->viewAny($customer))->toBeFalse();
    });
});

describe('UserPolicy::viewAdmin()', function (): void {
    it('allows callers with users.view to view any target', function (): void {
        $caller = User::factory()->admin(UserRole::Support->value)->create();
        $target = User::factory()->customer()->create();
        expect($this->policy->viewAdmin($caller, $target))->toBeTrue();
    });

    it('denies operations even when target is a customer', function (): void {
        $caller = User::factory()->admin(UserRole::Operations->value)->create();
        $target = User::factory()->customer()->create();
        expect($this->policy->viewAdmin($caller, $target))->toBeFalse();
    });
});

describe('UserPolicy::updateAdmin()', function (): void {
    it('allows super_admin updating a customer', function (): void {
        $caller = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->create();
        expect($this->policy->updateAdmin($caller, $target))->toBeTrue();
    });

    it('denies support (lacks users.update)', function (): void {
        $caller = User::factory()->admin(UserRole::Support->value)->create();
        $target = User::factory()->customer()->create();
        expect($this->policy->updateAdmin($caller, $target))->toBeFalse();
    });

    it('denies a non-super_admin (with users.update granted directly) from updating a super_admin target', function (): void {
        $caller = User::factory()->admin(UserRole::Sales->value)->create();
        $caller->givePermissionTo('users.update');

        $target = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->updateAdmin($caller, $target))->toBeFalse();
    });

    it('allows a super_admin updating another super_admin', function (): void {
        $caller = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->updateAdmin($caller, $target))->toBeTrue();
    });
});

describe('UserPolicy::changeRole()', function (): void {
    it('allows super_admin', function (): void {
        $caller = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->create();
        expect($this->policy->changeRole($caller, $target))->toBeTrue();
    });

    it('denies non-super_admin even when granted users.update directly', function (): void {
        $caller = User::factory()->admin(UserRole::Sales->value)->create();
        $caller->givePermissionTo('users.update');
        $target = User::factory()->customer()->create();
        expect($this->policy->changeRole($caller, $target))->toBeFalse();
    });

    it('denies all other admin roles', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $operations = User::factory()->admin(UserRole::Operations->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $target = User::factory()->customer()->create();

        expect($this->policy->changeRole($support, $target))->toBeFalse()
            ->and($this->policy->changeRole($operations, $target))->toBeFalse()
            ->and($this->policy->changeRole($sales, $target))->toBeFalse();
    });
});

describe('UserPolicy::deactivate()', function (): void {
    it('allows super_admin deactivating a customer', function (): void {
        $caller = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $target = User::factory()->customer()->create();
        expect($this->policy->deactivate($caller, $target))->toBeTrue();
    });

    it('denies callers without users.deactivate (support, operations, sales)', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $operations = User::factory()->admin(UserRole::Operations->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $target = User::factory()->customer()->create();

        expect($this->policy->deactivate($support, $target))->toBeFalse()
            ->and($this->policy->deactivate($operations, $target))->toBeFalse()
            ->and($this->policy->deactivate($sales, $target))->toBeFalse();
    });

    it('denies a non-super_admin with users.deactivate granted from deactivating a super_admin target', function (): void {
        $caller = User::factory()->admin(UserRole::Sales->value)->create();
        $caller->givePermissionTo('users.deactivate');

        $target = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->deactivate($caller, $target))->toBeFalse();
    });
});

describe('UserPolicy customer methods are still intact', function (): void {
    it('view/update/delete remain self-only', function (): void {
        $a = User::factory()->customer()->create();
        $b = User::factory()->customer()->create();

        expect($this->policy->view($a, $a))->toBeTrue()
            ->and($this->policy->view($a, $b))->toBeFalse()
            ->and($this->policy->update($a, $a))->toBeTrue()
            ->and($this->policy->update($a, $b))->toBeFalse()
            ->and($this->policy->delete($a, $a))->toBeTrue()
            ->and($this->policy->delete($a, $b))->toBeFalse();
    });
});
