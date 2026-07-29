<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| AuditLogPolicy — super_admin only
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md § 6.2.1.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new AuditLogPolicy;
});

dataset('audit_view_matrix', [
    'super_admin' => [UserRole::SuperAdmin->value, true],
    'operations' => [UserRole::Operations->value, false],
    'sales' => [UserRole::Sales->value, false],
    'support' => [UserRole::Support->value, false],
    'customer' => [UserRole::Customer->value, false],
]);

it('AuditLogPolicy::viewAny() role matrix', function (string $role, bool $expected): void {
    $factory = $role === UserRole::Customer->value
        ? User::factory()->customer()
        : User::factory()->admin($role);
    $user = $factory->create();

    expect($this->policy->viewAny($user))->toBe($expected);
})->with('audit_view_matrix');
