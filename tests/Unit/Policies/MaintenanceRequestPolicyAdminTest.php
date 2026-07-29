<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Product;
use App\Models\User;
use App\Policies\MaintenanceRequestPolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| MaintenanceRequestPolicy — admin methods (Phase 18)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 6.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->policy = new MaintenanceRequestPolicy;
});

function policyAdminMaintRequest(): MaintenanceRequest
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $owner = User::factory()->customer()->create();
    $address = Address::factory()->forUser($owner)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($owner)->forProduct($product)
        ->state(['address_id' => $address->id])->create();

    return MaintenanceRequest::factory()->forUser($owner)->forMachine($machine)->create();
}

describe('MaintenanceRequestPolicy::viewAnyAdmin()', function (): void {
    it('allows super_admin, operations, support (maintenance.view)', function (): void {
        foreach (
            [
                UserRole::SuperAdmin->value,
                UserRole::Operations->value,
                UserRole::Support->value,
            ] as $role
        ) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAnyAdmin($admin))->toBeTrue();
        }
    });

    it('denies sales (no maintenance.view)', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        expect($this->policy->viewAnyAdmin($sales))->toBeFalse();
    });

    it('denies a customer', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->viewAnyAdmin($customer))->toBeFalse();
    });
});

describe('MaintenanceRequestPolicy::viewAdmin()', function (): void {
    it('allows maintenance.view grantees', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->viewAdmin($support, $req))->toBeTrue();
    });

    it('denies sales', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->viewAdmin($sales, $req))->toBeFalse();
    });
});

describe('MaintenanceRequestPolicy::createAdmin()', function (): void {
    it('allows super_admin + operations', function (): void {
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();

        expect($this->policy->createAdmin($super))->toBeTrue()
            ->and($this->policy->createAdmin($ops))->toBeTrue();
    });

    it('denies sales and support', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $support = User::factory()->admin(UserRole::Support->value)->create();

        expect($this->policy->createAdmin($sales))->toBeFalse()
            ->and($this->policy->createAdmin($support))->toBeFalse();
    });
});

describe('MaintenanceRequestPolicy::assignAdmin()', function (): void {
    it('allows super_admin + operations (maintenance.assign)', function (): void {
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->assignAdmin($super, $req))->toBeTrue()
            ->and($this->policy->assignAdmin($ops, $req))->toBeTrue();
    });

    it('denies support (read-only) and sales', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->assignAdmin($support, $req))->toBeFalse()
            ->and($this->policy->assignAdmin($sales, $req))->toBeFalse();
    });
});

describe('MaintenanceRequestPolicy::updateStatusAdmin()', function (): void {
    it('allows super_admin + operations (maintenance.update)', function (): void {
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->updateStatusAdmin($super, $req))->toBeTrue()
            ->and($this->policy->updateStatusAdmin($ops, $req))->toBeTrue();
    });

    it('denies support and sales', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->updateStatusAdmin($support, $req))->toBeFalse()
            ->and($this->policy->updateStatusAdmin($sales, $req))->toBeFalse();
    });
});

describe('MaintenanceRequestPolicy::closeAdmin()', function (): void {
    it('allows super_admin + operations (maintenance.close)', function (): void {
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->closeAdmin($super, $req))->toBeTrue()
            ->and($this->policy->closeAdmin($ops, $req))->toBeTrue();
    });

    it('denies support and sales', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $req = policyAdminMaintRequest();

        expect($this->policy->closeAdmin($support, $req))->toBeFalse()
            ->and($this->policy->closeAdmin($sales, $req))->toBeFalse();
    });
});
