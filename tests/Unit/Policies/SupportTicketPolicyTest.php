<?php

declare(strict_types=1);

use App\Models\SupportTicket;
use App\Models\User;
use App\Policies\SupportTicketPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->policy = new SupportTicketPolicy;
});

describe('SupportTicketPolicy::viewAny()', function (): void {
    it('allows any authenticated user (controller scopes by user_id)', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->viewAny($customer))->toBeTrue();
    });
});

describe('SupportTicketPolicy::view()', function (): void {
    it('allows the owner to view their own ticket', function (): void {
        $owner = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($owner)->open()->create();

        expect($this->policy->view($owner, $ticket))->toBeTrue();
    });

    it('denies another customer from viewing the ticket', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($owner)->open()->create();

        expect($this->policy->view($stranger, $ticket))->toBeFalse();
    });

    it('denies a super_admin via the customer-side policy (admin permissions live in Phase 19)', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $ticket = SupportTicket::factory()->forUser($owner)->open()->create();

        expect($this->policy->view($admin, $ticket))->toBeFalse();
    });
});

describe('SupportTicketPolicy::create()', function (): void {
    it('allows any authenticated user (per-related-resource ownership lives on the Form Request rules)', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->create($customer))->toBeTrue();
    });

    it('allows an admin too (Phase 19 will pile permission checks on top)', function (): void {
        $admin = User::factory()->admin()->create();
        expect($this->policy->create($admin))->toBeTrue();
    });
});

describe('SupportTicketPolicy::cancel()', function (): void {
    it('allows the owner to cancel their own ticket', function (): void {
        $owner = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($owner)->open()->create();

        expect($this->policy->cancel($owner, $ticket))->toBeTrue();
    });

    it('denies a stranger from cancelling the ticket', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($owner)->open()->create();

        expect($this->policy->cancel($stranger, $ticket))->toBeFalse();
    });

    it('denies a super_admin from cancelling via the customer policy', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $ticket = SupportTicket::factory()->forUser($owner)->open()->create();

        expect($this->policy->cancel($admin, $ticket))->toBeFalse();
    });
});
