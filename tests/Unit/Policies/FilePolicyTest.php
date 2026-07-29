<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\File;
use App\Models\User;
use App\Policies\FilePolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new FilePolicy;
});

describe('FilePolicy::view()', function (): void {
    it('allows the owner to view their own private file', function (): void {
        $owner = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->private()->create();

        expect($this->policy->view($owner, $file))->toBeTrue();
    });

    it('allows ANY user to view a file flagged is_public=true', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->public()->create();

        expect($this->policy->view($stranger, $file))->toBeTrue();
    });

    it('denies a non-owner customer from viewing a private file', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->private()->create();

        expect($this->policy->view($stranger, $file))->toBeFalse();
    });

    it('allows a super_admin to view someone else\'s private file', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $file = File::factory()->for($owner)->private()->create();

        expect($this->policy->view($admin, $file))->toBeTrue();
    });

    it('allows operations / support roles to view private files', function (): void {
        $owner = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->private()->create();

        $ops = User::factory()->create();
        $ops->assignRole(UserRole::Operations->value);

        $support = User::factory()->create();
        $support->assignRole(UserRole::Support->value);

        expect($this->policy->view($ops, $file))->toBeTrue()
            ->and($this->policy->view($support, $file))->toBeTrue();
    });

    it('denies the `sales` role from viewing customers\' private files', function (): void {
        $owner = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->private()->create();

        $sales = User::factory()->create();
        $sales->assignRole(UserRole::Sales->value);

        expect($this->policy->view($sales, $file))->toBeFalse();
    });
});

describe('FilePolicy::delete()', function (): void {
    it('allows the owner to delete their own file', function (): void {
        $owner = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->public()->create();

        expect($this->policy->delete($owner, $file))->toBeTrue();
    });

    it('allows a super_admin to delete any file', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $file = File::factory()->for($owner)->public()->create();

        expect($this->policy->delete($admin, $file))->toBeTrue();
    });

    it('denies another customer from deleting someone else\'s file', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->public()->create();

        expect($this->policy->delete($stranger, $file))->toBeFalse();
    });

    it('denies operations / sales / support from deleting customer-owned files', function (): void {
        $owner = User::factory()->customer()->create();
        $file = File::factory()->for($owner)->public()->create();

        foreach ([UserRole::Operations, UserRole::Sales, UserRole::Support] as $role) {
            $actor = User::factory()->create();
            $actor->assignRole($role->value);
            expect($this->policy->delete($actor, $file))->toBeFalse();
        }
    });
});
