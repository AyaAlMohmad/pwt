<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use App\Policies\NotificationPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| NotificationPolicy unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 8.
| viewAny always true (controller scopes). view + update enforce
| ownership (Notification.user_id === actor.id). Foreign-owned id surfaces
| as 403 not 404 (CLAUDE.md § 7.3).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new NotificationPolicy;
});

describe('NotificationPolicy::viewAny()', function (): void {
    it('always returns true (the controller scopes to the caller)', function (): void {
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();

        expect($this->policy->viewAny($customer))->toBeTrue();
        expect($this->policy->viewAny($admin))->toBeTrue();
    });
});

describe('NotificationPolicy::view()', function (): void {
    it('allows the owner to view their own notification', function (): void {
        $owner = User::factory()->customer()->create();
        $notif = Notification::factory()->state(['user_id' => $owner->id])->create();

        expect($this->policy->view($owner, $notif))->toBeTrue();
    });

    it('denies a different customer from viewing the notification', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $notif = Notification::factory()->state(['user_id' => $owner->id])->create();

        expect($this->policy->view($stranger, $notif))->toBeFalse();
    });

    it('denies even a super_admin from viewing customer notifications through the customer policy', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $notif = Notification::factory()->state(['user_id' => $owner->id])->create();

        expect($this->policy->view($admin, $notif))->toBeFalse();
    });
});

describe('NotificationPolicy::update()', function (): void {
    it('allows the owner to mark their own notification as read', function (): void {
        $owner = User::factory()->customer()->create();
        $notif = Notification::factory()->state(['user_id' => $owner->id])->create();

        expect($this->policy->update($owner, $notif))->toBeTrue();
    });

    it('denies a stranger from updating the notification', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $notif = Notification::factory()->state(['user_id' => $owner->id])->create();

        expect($this->policy->update($stranger, $notif))->toBeFalse();
    });

    it('denies an admin from updating customer notifications through the customer policy', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $notif = Notification::factory()->state(['user_id' => $owner->id])->create();

        expect($this->policy->update($admin, $notif))->toBeFalse();
    });
});
