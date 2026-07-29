<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Testimonial;
use App\Models\User;
use App\Policies\TestimonialPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new TestimonialPolicy;
});

describe('TestimonialPolicy — read (viewAny / view)', function (): void {
    it('allows super_admin and sales', function (): void {
        $t = Testimonial::factory()->create();

        foreach ([UserRole::SuperAdmin->value, UserRole::Sales->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAny($admin))->toBeTrue();
            expect($this->policy->view($admin, $t))->toBeTrue();
        }
    });

    it('denies operations and support', function (): void {
        $t = Testimonial::factory()->create();

        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAny($admin))->toBeFalse();
            expect($this->policy->view($admin, $t))->toBeFalse();
        }
    });

    it('denies plain customers', function (): void {
        $customer = User::factory()->customer()->create();
        $t = Testimonial::factory()->create();
        expect($this->policy->viewAny($customer))->toBeFalse();
        expect($this->policy->view($customer, $t))->toBeFalse();
    });
});

describe('TestimonialPolicy — write (create / update)', function (): void {
    it('allows super_admin and sales', function (): void {
        foreach ([UserRole::SuperAdmin->value, UserRole::Sales->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->create($admin))->toBeTrue();
            expect($this->policy->update($admin, Testimonial::factory()->create()))->toBeTrue();
        }
    });

    it('denies operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->create($admin))->toBeFalse();
            expect($this->policy->update($admin, Testimonial::factory()->create()))->toBeFalse();
        }
    });
});

describe('TestimonialPolicy::delete — super_admin only', function (): void {
    it('allows super_admin', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        expect($this->policy->delete($admin, Testimonial::factory()->create()))->toBeTrue();
    });

    it('denies sales even though they hold testimonials.delete via seeder', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        expect($sales->can('testimonials.delete'))->toBeTrue();
        expect($this->policy->delete($sales, Testimonial::factory()->create()))->toBeFalse();
    });

    it('denies operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->delete($admin, Testimonial::factory()->create()))->toBeFalse();
        }
    });
});
