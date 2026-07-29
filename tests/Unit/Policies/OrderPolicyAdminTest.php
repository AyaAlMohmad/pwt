<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->policy = new OrderPolicy;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function policyAdminOrder(): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();

    return Order::factory()->forUser($customer)->forAddress($address)->state([
        'currency' => 'AED',
    ])->create();
}

describe('OrderPolicy::viewAnyAdmin()', function (): void {
    it('allows super_admin (orders.view)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeTrue();
    });

    it('allows operations (orders.view)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeTrue();
    });

    it('allows support (orders.view granted in seeder)', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeTrue();
    });

    it('denies sales (no orders.view in seeder)', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        expect($this->policy->viewAnyAdmin($admin))->toBeFalse();
    });

    it('denies a customer', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->viewAnyAdmin($customer))->toBeFalse();
    });
});

describe('OrderPolicy::viewAdmin()', function (): void {
    it('allows orders.view-grantees regardless of ownership', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->viewAdmin($support, $order))->toBeTrue();
    });

    it('denies sales (no orders.view)', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->viewAdmin($sales, $order))->toBeFalse();
    });

    it('denies plain customers', function (): void {
        $customer = User::factory()->customer()->create();
        $order = policyAdminOrder();
        expect($this->policy->viewAdmin($customer, $order))->toBeFalse();
    });
});

describe('OrderPolicy::updateStatusAdmin()', function (): void {
    it('allows super_admin (orders.update-status)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->updateStatusAdmin($admin, $order))->toBeTrue();
    });

    it('allows operations', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->updateStatusAdmin($admin, $order))->toBeTrue();
    });

    it('denies support (read-only)', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->updateStatusAdmin($admin, $order))->toBeFalse();
    });

    it('denies sales', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->updateStatusAdmin($admin, $order))->toBeFalse();
    });
});

describe('OrderPolicy::cancelAdmin()', function (): void {
    it('allows operations (orders.cancel-any)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->cancelAdmin($admin, $order))->toBeTrue();
    });

    it('denies support and sales (no orders.cancel-any)', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $order = policyAdminOrder();

        expect($this->policy->cancelAdmin($support, $order))->toBeFalse()
            ->and($this->policy->cancelAdmin($sales, $order))->toBeFalse();
    });
});

describe('OrderPolicy::scheduleAdmin()', function (): void {
    it('allows operations (orders.schedule)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $order = policyAdminOrder();
        expect($this->policy->scheduleAdmin($admin, $order))->toBeTrue();
    });

    it('denies support and sales', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $order = policyAdminOrder();

        expect($this->policy->scheduleAdmin($support, $order))->toBeFalse()
            ->and($this->policy->scheduleAdmin($sales, $order))->toBeFalse();
    });
});

describe('OrderPolicy::addNoteAdmin()', function (): void {
    it('allows orders.update-status grantees (super_admin, operations)', function (): void {
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $order = policyAdminOrder();

        expect($this->policy->addNoteAdmin($super, $order))->toBeTrue()
            ->and($this->policy->addNoteAdmin($ops, $order))->toBeTrue();
    });

    it('denies support and sales', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $order = policyAdminOrder();

        expect($this->policy->addNoteAdmin($support, $order))->toBeFalse()
            ->and($this->policy->addNoteAdmin($sales, $order))->toBeFalse();
    });
});
