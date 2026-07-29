<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Promotion;
use App\Models\User;
use App\Policies\PromotionPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new PromotionPolicy;
});

describe('PromotionPolicy — read (viewAny / view)', function (): void {
    it('allows super_admin and sales', function (): void {
        $promotion = Promotion::factory()->create();

        foreach ([UserRole::SuperAdmin->value, UserRole::Sales->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAny($admin))->toBeTrue();
            expect($this->policy->view($admin, $promotion))->toBeTrue();
        }
    });

    it('denies operations and support', function (): void {
        $promotion = Promotion::factory()->create();

        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAny($admin))->toBeFalse();
            expect($this->policy->view($admin, $promotion))->toBeFalse();
        }
    });

    it('denies plain customers', function (): void {
        $customer = User::factory()->customer()->create();
        $promotion = Promotion::factory()->create();
        expect($this->policy->viewAny($customer))->toBeFalse();
        expect($this->policy->view($customer, $promotion))->toBeFalse();
    });
});

describe('PromotionPolicy — write (create / update)', function (): void {
    it('allows super_admin and sales', function (): void {
        foreach ([UserRole::SuperAdmin->value, UserRole::Sales->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->create($admin))->toBeTrue();
            expect($this->policy->update($admin, Promotion::factory()->create()))->toBeTrue();
        }
    });

    it('denies operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->create($admin))->toBeFalse();
            expect($this->policy->update($admin, Promotion::factory()->create()))->toBeFalse();
        }
    });
});

describe('PromotionPolicy::delete — super_admin only', function (): void {
    it('allows super_admin', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->delete($admin, Promotion::factory()->create()))->toBeTrue();
    });

    it('denies sales even though they hold promotions.delete via seeder', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        expect($sales->can('promotions.delete'))->toBeTrue();
        expect($this->policy->delete($sales, Promotion::factory()->create()))->toBeFalse();
    });

    it('denies operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->delete($admin, Promotion::factory()->create()))->toBeFalse();
        }
    });
});
