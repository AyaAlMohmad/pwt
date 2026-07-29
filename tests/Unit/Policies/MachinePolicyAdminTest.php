<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Product;
use App\Models\User;
use App\Policies\MachinePolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| MachinePolicy — admin methods (Phase 18)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 6.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->policy = new MachinePolicy;
});

function policyAdminMachine(): Machine
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $owner = User::factory()->customer()->create();
    $address = Address::factory()->forUser($owner)->forCountry($uae)->create();
    $product = Product::factory()->create();

    return Machine::factory()->forUser($owner)->forProduct($product)
        ->state(['address_id' => $address->id])->create();
}

describe('MachinePolicy::viewAnyAdmin()', function (): void {
    it('allows super_admin (machines.view)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeTrue();
    });

    it('allows operations (machines.view)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeTrue();
    });

    it('denies sales (no machines.view)', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeFalse();
    });

    it('denies support (no machines.view per seeder)', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeFalse();
    });

    it('denies a customer', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->viewAnyAdmin($customer))->toBeFalse();
    });
});

describe('MachinePolicy::viewAdmin()', function (): void {
    it('allows machines.view grantees regardless of ownership', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $machine = policyAdminMachine();
        expect($this->policy->viewAdmin($admin, $machine))->toBeTrue();
    });

    it('denies sales', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $machine = policyAdminMachine();
        expect($this->policy->viewAdmin($sales, $machine))->toBeFalse();
    });

    it('denies support', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $machine = policyAdminMachine();
        expect($this->policy->viewAdmin($support, $machine))->toBeFalse();
    });
});

describe('MachinePolicy::createAdmin()', function (): void {
    it('allows super_admin + operations (machines.create)', function (): void {
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

describe('MachinePolicy::updateAdmin()', function (): void {
    it('allows super_admin + operations (machines.update)', function (): void {
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $machine = policyAdminMachine();

        expect($this->policy->updateAdmin($super, $machine))->toBeTrue()
            ->and($this->policy->updateAdmin($ops, $machine))->toBeTrue();
    });

    it('denies sales and support', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $machine = policyAdminMachine();

        expect($this->policy->updateAdmin($sales, $machine))->toBeFalse()
            ->and($this->policy->updateAdmin($support, $machine))->toBeFalse();
    });
});

describe('MachinePolicy::dueMaintenanceAdmin()', function (): void {
    it('mirrors viewAnyAdmin (machines.view)', function (): void {
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();

        expect($this->policy->dueMaintenanceAdmin($ops))->toBeTrue()
            ->and($this->policy->dueMaintenanceAdmin($sales))->toBeFalse();
    });
});
