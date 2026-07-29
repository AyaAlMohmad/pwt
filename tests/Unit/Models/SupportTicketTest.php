<?php

declare(strict_types=1);

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| SupportTicket model — relations, scopes, casts, factory states.
|--------------------------------------------------------------------------
|
| Contract: app/Models/SupportTicket.php + docs/architecture/support.md §
| 11.1. Confirms relations, query scopes, the `messages` ordering, and
| the `is_cancellable` computed attribute.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

describe('SupportTicket relations', function (): void {
    it('belongsTo user', function (): void {
        $user = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        expect($ticket->user)->not->toBeNull();
        expect($ticket->user->id)->toBe($user->id);
    });

    it('belongsTo relatedOrder when set', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = Order::factory()->forUser($user)->forAddress($address)
            ->state(['currency' => 'AED'])->create();

        $ticket = SupportTicket::factory()->forUser($user)
            ->state(['related_order_id' => $order->id])->create();

        expect($ticket->relatedOrder)->not->toBeNull();
        expect($ticket->relatedOrder->id)->toBe($order->id);
    });

    it('belongsTo assignedTo when an admin is set', function (): void {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $ticket = SupportTicket::factory()->forUser($user)->assigned($admin)->create();

        expect($ticket->assignedTo)->not->toBeNull();
        expect($ticket->assignedTo->id)->toBe($admin->id);
    });

    it('hasMany messages ordered by created_at', function (): void {
        $user = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $middle = SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($user)
            ->state(['created_at' => now()->subHour()])->create();
        $latest = SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($user)
            ->state(['created_at' => now()])->create();
        $earliest = SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($user)
            ->state(['created_at' => now()->subDay()])->create();

        $ids = $ticket->messages()->get()->pluck('id')->all();
        expect($ids)->toBe([$earliest->id, $middle->id, $latest->id]);
    });

    it('hasMany statusHistory ordered chronologically', function (): void {
        $user = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $first = SupportTicketStatusHistory::factory()->forTicket($ticket)
            ->transition(null, SupportTicketStatus::Open)
            ->state(['created_at' => now()->subHour(), 'actor_user_id' => $user->id])->create();
        $second = SupportTicketStatusHistory::factory()->forTicket($ticket)
            ->transition(SupportTicketStatus::Open, SupportTicketStatus::Cancelled)
            ->state(['created_at' => now(), 'actor_user_id' => $user->id])->create();

        $ids = $ticket->statusHistory()->get()->pluck('id')->all();
        expect($ids)->toBe([$first->id, $second->id]);
    });
});

describe('SupportTicket scopes', function (): void {
    it('forUser() narrows to a single user_id', function (): void {
        $a = User::factory()->customer()->create();
        $b = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($a)->open()->create();
        SupportTicket::factory()->forUser($a)->open()->create();
        SupportTicket::factory()->forUser($b)->open()->create();

        expect(SupportTicket::query()->forUser($a->id)->count())->toBe(2);
        expect(SupportTicket::query()->forUser($b->id)->count())->toBe(1);
    });

    it('withStatus() accepts a single status string', function (): void {
        $u = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($u)->open()->create();
        SupportTicket::factory()->forUser($u)->cancelled()->create();

        expect(SupportTicket::query()->withStatus('cancelled')->count())->toBe(1);
    });

    it('withStatus() accepts a list of status values', function (): void {
        $u = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($u)->open()->create();
        SupportTicket::factory()->forUser($u)->cancelled()->create();
        SupportTicket::factory()->forUser($u)->closed()->create();

        expect(SupportTicket::query()->withStatus(['open', 'closed'])->count())->toBe(2);
    });

    it('withStatus() returns all rows when status is null / empty', function (): void {
        $u = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($u)->open()->create();
        SupportTicket::factory()->forUser($u)->cancelled()->create();

        expect(SupportTicket::query()->withStatus(null)->count())->toBe(2);
        expect(SupportTicket::query()->withStatus('')->count())->toBe(2);
        expect(SupportTicket::query()->withStatus([])->count())->toBe(2);
    });

    it('withCategory() filters by category', function (): void {
        $u = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($u)
            ->withCategory(SupportTicketCategory::OrderIssue)->open()->create();
        SupportTicket::factory()->forUser($u)
            ->withCategory(SupportTicketCategory::Feedback)->open()->create();

        expect(SupportTicket::query()->withCategory('order_issue')->count())->toBe(1);
        expect(SupportTicket::query()->withCategory(null)->count())->toBe(2);
        expect(SupportTicket::query()->withCategory('')->count())->toBe(2);
    });
});

describe('SupportTicket::getIsCancellableAttribute()', function (): void {
    it('returns true only for the open state', function (): void {
        $u = User::factory()->customer()->create();
        $open = SupportTicket::factory()->forUser($u)->open()->create();
        $cancelled = SupportTicket::factory()->forUser($u)->cancelled()->create();
        $closed = SupportTicket::factory()->forUser($u)->closed()->create();

        expect($open->is_cancellable)->toBeTrue();
        expect($cancelled->is_cancellable)->toBeFalse();
        expect($closed->is_cancellable)->toBeFalse();
    });
});

describe('SupportTicket casts and public_id auto-fill', function (): void {
    it('auto-populates public_id on creating when not supplied', function (): void {
        $u = User::factory()->customer()->create();
        $ticket = new SupportTicket;
        $ticket->user_id = $u->id;
        $ticket->subject = 'Auto public_id';
        $ticket->category = SupportTicketCategory::Other->value;
        $ticket->priority = 'medium';
        $ticket->status = SupportTicketStatus::Open->value;
        $ticket->opened_at = now();
        $ticket->save();

        expect($ticket->public_id)->not->toBeEmpty();
        expect($ticket->public_id)->toMatch('/^[0-9a-f-]{36}$/');
    });

    it('casts status / category / priority to the matching enum instances', function (): void {
        $u = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($u)->open()
            ->withCategory(SupportTicketCategory::PaymentIssue)->create();

        expect($ticket->status)->toBeInstanceOf(SupportTicketStatus::class);
        expect($ticket->category)->toBeInstanceOf(SupportTicketCategory::class);
        expect($ticket->category)->toBe(SupportTicketCategory::PaymentIssue);
    });
});
