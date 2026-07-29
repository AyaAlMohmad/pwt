<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Policies\NotificationTemplatePolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| NotificationTemplatePolicy — super_admin only
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md § 6.2.2.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new NotificationTemplatePolicy;
    $this->template = NotificationTemplate::factory()
        ->state([
            'key' => 'order.confirmed',
            'channel' => NotificationChannel::InApp->value,
        ])
        ->create();
});

dataset('template_role_matrix', [
    'super_admin' => [UserRole::SuperAdmin->value, true],
    'operations' => [UserRole::Operations->value, false],
    'sales' => [UserRole::Sales->value, false],
    'support' => [UserRole::Support->value, false],
    'customer' => [UserRole::Customer->value, false],
]);

it('NotificationTemplatePolicy::viewAny() role matrix', function (string $role, bool $expected): void {
    $factory = $role === UserRole::Customer->value
        ? User::factory()->customer()
        : User::factory()->admin($role);
    $user = $factory->create();

    expect($this->policy->viewAny($user))->toBe($expected);
})->with('template_role_matrix');

it('NotificationTemplatePolicy::view() role matrix', function (string $role, bool $expected): void {
    $factory = $role === UserRole::Customer->value
        ? User::factory()->customer()
        : User::factory()->admin($role);
    $user = $factory->create();

    expect($this->policy->view($user, $this->template))->toBe($expected);
})->with('template_role_matrix');

it('NotificationTemplatePolicy::update() role matrix', function (string $role, bool $expected): void {
    $factory = $role === UserRole::Customer->value
        ? User::factory()->customer()
        : User::factory()->admin($role);
    $user = $factory->create();

    expect($this->policy->update($user, $this->template))->toBe($expected);
})->with('template_role_matrix');
