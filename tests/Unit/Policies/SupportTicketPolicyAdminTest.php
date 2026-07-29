<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\SupportTicketPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| SupportTicketPolicy — admin methods (Phase 19)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-support.md § 1.4. The customer-side
| methods (`viewAny`, `view`, `create`, `cancel`) are exercised by
| SupportTicketPolicyTest; this file covers `viewAnyAdmin` /
| `viewAdmin` / `updateAdmin` / `replyAdmin`.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new SupportTicketPolicy;
});

function policyAdminSupportTicket(): SupportTicket
{
    $owner = User::factory()->customer()->create();

    return SupportTicket::factory()->forUser($owner)->open()->create();
}

describe('SupportTicketPolicy::viewAnyAdmin()', function (): void {
    it('allows super_admin, support, and operations (all hold support.view)', function (): void {
        foreach (
            [
                UserRole::SuperAdmin->value,
                UserRole::Support->value,
                UserRole::Operations->value,
            ] as $role
        ) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAnyAdmin($admin))->toBeTrue();
        }
    });

    it('denies sales (no support.view)', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        expect($this->policy->viewAnyAdmin($sales))->toBeFalse();
    });

    it('denies a customer', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->viewAnyAdmin($customer))->toBeFalse();
    });
});

describe('SupportTicketPolicy::viewAdmin()', function (): void {
    it('allows support.view grantees regardless of ownership', function (): void {
        $ticket = policyAdminSupportTicket();
        foreach (
            [
                UserRole::SuperAdmin->value,
                UserRole::Support->value,
                UserRole::Operations->value,
            ] as $role
        ) {
            $admin = User::factory()->admin($role)->create();
            expect($this->policy->viewAdmin($admin, $ticket))->toBeTrue();
        }
    });

    it('denies sales', function (): void {
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $ticket = policyAdminSupportTicket();
        expect($this->policy->viewAdmin($sales, $ticket))->toBeFalse();
    });

    it('denies customers via the admin gate', function (): void {
        $customer = User::factory()->customer()->create();
        $ticket = policyAdminSupportTicket();
        expect($this->policy->viewAdmin($customer, $ticket))->toBeFalse();
    });
});

describe('SupportTicketPolicy::updateAdmin()', function (): void {
    it('allows super_admin + support (support.update)', function (): void {
        $ticket = policyAdminSupportTicket();
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $support = User::factory()->admin(UserRole::Support->value)->create();

        expect($this->policy->updateAdmin($super, $ticket))->toBeTrue()
            ->and($this->policy->updateAdmin($support, $ticket))->toBeTrue();
    });

    it('denies operations (read-only) and sales', function (): void {
        $ticket = policyAdminSupportTicket();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();

        expect($this->policy->updateAdmin($ops, $ticket))->toBeFalse()
            ->and($this->policy->updateAdmin($sales, $ticket))->toBeFalse();
    });

    it('denies customers', function (): void {
        $customer = User::factory()->customer()->create();
        $ticket = policyAdminSupportTicket();
        expect($this->policy->updateAdmin($customer, $ticket))->toBeFalse();
    });
});

describe('SupportTicketPolicy::replyAdmin()', function (): void {
    it('allows super_admin + support (support.update is the collapsed gate)', function (): void {
        $ticket = policyAdminSupportTicket();
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $support = User::factory()->admin(UserRole::Support->value)->create();

        expect($this->policy->replyAdmin($super, $ticket))->toBeTrue()
            ->and($this->policy->replyAdmin($support, $ticket))->toBeTrue();
    });

    it('denies operations and sales', function (): void {
        $ticket = policyAdminSupportTicket();
        $ops = User::factory()->admin(UserRole::Operations->value)->create();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();

        expect($this->policy->replyAdmin($ops, $ticket))->toBeFalse()
            ->and($this->policy->replyAdmin($sales, $ticket))->toBeFalse();
    });

    it('denies customers', function (): void {
        $customer = User::factory()->customer()->create();
        $ticket = policyAdminSupportTicket();
        expect($this->policy->replyAdmin($customer, $ticket))->toBeFalse();
    });
});
