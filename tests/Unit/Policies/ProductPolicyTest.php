<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new ProductPolicy;
});

describe('ProductPolicy — read (viewAny / view)', function (): void {
    it('allows super_admin, sales, operations, support', function (): void {
        $product = Product::factory()->create();

        foreach ([
            UserRole::SuperAdmin->value,
            UserRole::Sales->value,
            UserRole::Operations->value,
            UserRole::Support->value,
        ] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAny($admin))->toBeTrue();
            expect($this->policy->view($admin, $product))->toBeTrue();
        }
    });

    it('denies plain customers', function (): void {
        $customer = User::factory()->customer()->create();
        $product = Product::factory()->create();

        expect($this->policy->viewAny($customer))->toBeFalse();
        expect($this->policy->view($customer, $product))->toBeFalse();
    });
});

describe('ProductPolicy — write (create / update / updateStatus / manageImages)', function (): void {
    it('allows super_admin and sales for create, update, updateStatus, manageImages', function (): void {
        foreach ([UserRole::SuperAdmin->value, UserRole::Sales->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            $product = Product::factory()->create();
            expect($this->policy->create($admin))->toBeTrue();
            expect($this->policy->update($admin, $product))->toBeTrue();
            expect($this->policy->updateStatus($admin, $product))->toBeTrue();
            expect($this->policy->manageImages($admin, $product))->toBeTrue();
        }
    });

    it('denies operations and support for every write method', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            $product = Product::factory()->create();
            expect($this->policy->create($admin))->toBeFalse();
            expect($this->policy->update($admin, $product))->toBeFalse();
            expect($this->policy->updateStatus($admin, $product))->toBeFalse();
            expect($this->policy->manageImages($admin, $product))->toBeFalse();
        }
    });
});

describe('ProductPolicy::delete — super_admin only', function (): void {
    it('allows super_admin', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->delete($admin, Product::factory()->create()))->toBeTrue();
    });

    it('denies sales even though they hold products.deactivate via seeder prefix grant', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        expect($sales->can('products.deactivate'))->toBeTrue();
        expect($this->policy->delete($sales, Product::factory()->create()))->toBeFalse();
    });

    it('denies operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->delete($admin, Product::factory()->create()))->toBeFalse();
        }
    });
});
