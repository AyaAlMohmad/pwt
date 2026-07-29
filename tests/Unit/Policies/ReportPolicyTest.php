<?php

declare(strict_types=1);

use App\Enums\ReportType;
use App\Enums\UserRole;
use App\Models\User;
use App\Policies\ReportPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ReportPolicy — role × report-type matrix
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-reports.md § 6.2 / § 6.4.
| - super_admin / operations / sales: any of the 4 report types
| - support: maintenance + customers only
| - customer: never (admin middleware rejects upstream, policy returns false)
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new ReportPolicy;
});

/**
 * @return list<array{0: string, 1: ReportType, 2: bool}>
 */
dataset('view_matrix', [
    // super_admin — yes to everything.
    'super_admin sales' => [UserRole::SuperAdmin->value, ReportType::Sales, true],
    'super_admin orders' => [UserRole::SuperAdmin->value, ReportType::Orders, true],
    'super_admin maintenance' => [UserRole::SuperAdmin->value, ReportType::Maintenance, true],
    'super_admin customers' => [UserRole::SuperAdmin->value, ReportType::Customers, true],

    // operations — yes to everything.
    'operations sales' => [UserRole::Operations->value, ReportType::Sales, true],
    'operations orders' => [UserRole::Operations->value, ReportType::Orders, true],
    'operations maintenance' => [UserRole::Operations->value, ReportType::Maintenance, true],
    'operations customers' => [UserRole::Operations->value, ReportType::Customers, true],

    // sales — yes to everything.
    'sales sales' => [UserRole::Sales->value, ReportType::Sales, true],
    'sales orders' => [UserRole::Sales->value, ReportType::Orders, true],
    'sales maintenance' => [UserRole::Sales->value, ReportType::Maintenance, true],
    'sales customers' => [UserRole::Sales->value, ReportType::Customers, true],

    // support — only maintenance + customers.
    'support sales' => [UserRole::Support->value, ReportType::Sales, false],
    'support orders' => [UserRole::Support->value, ReportType::Orders, false],
    'support maintenance' => [UserRole::Support->value, ReportType::Maintenance, true],
    'support customers' => [UserRole::Support->value, ReportType::Customers, true],

    // customer — never. The `admin` middleware (Phase 14) rejects the
    // token upstream in the HTTP layer, but the policy also returns
    // false when called directly (customer role lacks both reports.view
    // and reports.export permissions).
    'customer sales' => [UserRole::Customer->value, ReportType::Sales, false],
    'customer orders' => [UserRole::Customer->value, ReportType::Orders, false],
    'customer maintenance' => [UserRole::Customer->value, ReportType::Maintenance, false],
    'customer customers' => [UserRole::Customer->value, ReportType::Customers, false],
]);

it('ReportPolicy::view() role × type matrix', function (string $role, ReportType $type, bool $expected): void {
    $factory = $role === UserRole::Customer->value
        ? User::factory()->customer()
        : User::factory()->admin($role);
    $user = $factory->create();

    expect($this->policy->view($user, $type))->toBe($expected);
})->with('view_matrix');

it('ReportPolicy::export() role × type matrix', function (string $role, ReportType $type, bool $expected): void {
    $factory = $role === UserRole::Customer->value
        ? User::factory()->customer()
        : User::factory()->admin($role);
    $user = $factory->create();

    expect($this->policy->export($user, $type))->toBe($expected);
})->with('view_matrix');

describe('ReportPolicy — type narrowing for the `support` role', function (): void {
    it('allows maintenance but blocks sales (the same user, different types)', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();

        expect($this->policy->view($support, ReportType::Maintenance))->toBeTrue();
        expect($this->policy->view($support, ReportType::Customers))->toBeTrue();
        expect($this->policy->view($support, ReportType::Sales))->toBeFalse();
        expect($this->policy->view($support, ReportType::Orders))->toBeFalse();
    });

    it('mirrors the read matrix on export()', function (): void {
        $support = User::factory()->admin(UserRole::Support->value)->create();

        expect($this->policy->export($support, ReportType::Maintenance))->toBeTrue();
        expect($this->policy->export($support, ReportType::Sales))->toBeFalse();
    });
});
