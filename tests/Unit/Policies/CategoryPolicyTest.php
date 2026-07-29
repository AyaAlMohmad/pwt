<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use App\Policies\CategoryPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new CategoryPolicy;
});

describe('CategoryPolicy — read (viewAny / view)', function (): void {
    it('allows super_admin, sales, operations, support', function (): void {
        $category = Category::factory()->create();

        foreach ([
            UserRole::SuperAdmin->value,
            UserRole::Sales->value,
            UserRole::Operations->value,
            UserRole::Support->value,
        ] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAny($admin))->toBeTrue();
            expect($this->policy->view($admin, $category))->toBeTrue();
        }
    });

    it('denies plain customers', function (): void {
        $customer = User::factory()->customer()->create();
        $category = Category::factory()->create();

        expect($this->policy->viewAny($customer))->toBeFalse();
        expect($this->policy->view($customer, $category))->toBeFalse();
    });
});

describe('CategoryPolicy — write (create / update)', function (): void {
    it('allows super_admin and sales', function (): void {
        foreach ([UserRole::SuperAdmin->value, UserRole::Sales->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->create($admin))->toBeTrue();
            expect($this->policy->update($admin, Category::factory()->create()))->toBeTrue();
        }
    });

    it('denies operations and support (read-only)', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->create($admin))->toBeFalse();
            expect($this->policy->update($admin, Category::factory()->create()))->toBeFalse();
        }
    });
});

describe('CategoryPolicy::delete — super_admin only', function (): void {
    it('allows super_admin', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->delete($admin, Category::factory()->create()))->toBeTrue();
    });

    it('denies sales even though they hold categories.delete via the seeder prefix grant', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        expect($sales->can('categories.delete'))->toBeTrue();
        expect($this->policy->delete($sales, Category::factory()->create()))->toBeFalse();
    });

    it('denies operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->delete($admin, Category::factory()->create()))->toBeFalse();
        }
    });
});
