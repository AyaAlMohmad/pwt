<?php

declare(strict_types=1);

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketMessageSenderType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Events\Support\SupportTicketAssigned;
use App\Events\Support\SupportTicketClosed;
use App\Events\Support\SupportTicketInProgress;
use App\Events\Support\SupportTicketReplied;
use App\Events\Support\SupportTicketResolved;
use App\Exceptions\Support\InvalidSupportTransitionException;
use App\Exceptions\Support\TicketTerminalException;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Admin\AdminSupportTicketService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| AdminSupportTicketService — unit coverage
|--------------------------------------------------------------------------
|
| Covers every public method per docs/architecture/admin-support.md § 7.5.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->service = app(AdminSupportTicketService::class);
});

/**
 * @return array{customer: User, admin: User, ticket: SupportTicket}
 */
function svcSupportFixture(array $overrides = []): array
{
    $customer = User::factory()->customer()->create();
    $admin = User::factory()->admin(UserRole::Support->value)->create();
    $ticket = SupportTicket::factory()->forUser($customer)->state($overrides)->create();

    return ['customer' => $customer, 'admin' => $admin, 'ticket' => $ticket];
}

describe('AdminSupportTicketService::list()', function (): void {
    it('paginates all rows by default (no filters)', function (): void {
        SupportTicket::factory()->forUser(User::factory()->customer()->create())->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())->create();

        expect($this->service->list([], 1, 50)->total())->toBe(2);
    });

    it('filters by status array', function (): void {
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['status' => SupportTicketStatus::Open->value])->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['status' => SupportTicketStatus::Closed->value, 'closed_at' => now()])->create();

        expect($this->service->list(['status' => ['open']], 1, 50)->total())->toBe(1);
    });

    it('filters by category', function (): void {
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['category' => SupportTicketCategory::OrderIssue->value])->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['category' => SupportTicketCategory::Other->value])->create();

        expect($this->service->list(['category' => 'order_issue'], 1, 50)->total())->toBe(1);
    });

    it('filters by priority', function (): void {
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['priority' => SupportTicketPriority::Urgent->value])->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['priority' => SupportTicketPriority::Low->value])->create();

        expect($this->service->list(['priority' => 'urgent'], 1, 50)->total())->toBe(1);
    });

    it('filters by assigned_to_user_id=0 (unassigned)', function (): void {
        $assignee = User::factory()->admin(UserRole::Support->value)->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['assigned_to_user_id' => $assignee->id, 'status' => SupportTicketStatus::Assigned->value])
            ->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->create();

        expect($this->service->list(['assigned_to_user_id' => 0], 1, 50)->total())->toBe(1);
    });

    it('filters by customer_id', function (): void {
        $target = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($target)->create();
        SupportTicket::factory()->forUser($target)->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())->create();

        expect($this->service->list(['customer_id' => $target->id], 1, 50)->total())->toBe(2);
    });

    it('filters by search across subject + public_id + customer name', function (): void {
        $target = User::factory()->customer()->state(['name' => 'Bilal Khan'])->create();
        SupportTicket::factory()->forUser($target)->state(['subject' => 'hello'])->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['subject' => 'unrelated'])->create();

        expect($this->service->list(['search' => 'Bilal'], 1, 50)->total())->toBe(1);
    });

    it('filters by date_from / date_to', function (): void {
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['created_at' => now()->subDays(10)])->create();
        SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['created_at' => now()->subDays(2)])->create();

        $from = now()->subDays(4)->toDateString();
        $to = now()->toDateString();
        expect($this->service->list(['date_from' => $from, 'date_to' => $to], 1, 50)->total())->toBe(1);
    });

    it('include_trashed=true surfaces soft-deleted rows', function (): void {
        $live = SupportTicket::factory()->forUser(User::factory()->customer()->create())->create();
        $trashed = SupportTicket::factory()->forUser(User::factory()->customer()->create())->create();
        $trashed->delete();

        expect($this->service->list(['include_trashed' => true], 1, 50)->total())->toBe(2);
        expect($this->service->list([], 1, 50)->total())->toBe(1);
    });

    it('sorts by last_message_at desc by default', function (): void {
        $a = SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['last_message_at' => now()->subDays(2)])->create();
        $b = SupportTicket::factory()->forUser(User::factory()->customer()->create())
            ->state(['last_message_at' => now()])->create();

        $page = $this->service->list([], 1, 50);
        expect($page->items()[0]->id)->toBe($b->id);
    });
});

describe('AdminSupportTicketService::find()', function (): void {
    it('returns a fully-loaded ticket', function (): void {
        ['ticket' => $ticket] = svcSupportFixture();

        $loaded = $this->service->find($ticket->id);
        expect($loaded->id)->toBe($ticket->id)
            ->and($loaded->relationLoaded('user'))->toBeTrue()
            ->and($loaded->relationLoaded('messages'))->toBeTrue()
            ->and($loaded->relationLoaded('statusHistory'))->toBeTrue();
    });

    it('returns soft-deleted tickets too (withTrashed())', function (): void {
        ['ticket' => $ticket] = svcSupportFixture();
        $ticket->delete();

        $loaded = $this->service->find($ticket->id);
        expect($loaded->id)->toBe($ticket->id)
            ->and($loaded->trashed())->toBeTrue();
    });

    it('throws ModelNotFoundException for an unknown id', function (): void {
        $this->service->find(9999999);
    })->throws(ModelNotFoundException::class);
});

describe('AdminSupportTicketService::update()', function (): void {
    it('priority-only payload updates priority and writes activity log', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Open->value,
            'priority' => SupportTicketPriority::Medium->value,
        ]);

        $this->service->update($ticket, ['priority' => 'urgent'], $admin, '127.0.0.1');

        expect($ticket->fresh()->priority)->toBe(SupportTicketPriority::Urgent);

        $activity = Activity::query()
            ->where('log_name', 'admin.support')
            ->where('event', 'admin.support.updated')
            ->where('subject_id', $ticket->id)
            ->latest('id')->first();
        expect($activity)->not->toBeNull()
            ->and($activity->causer_id)->toBe($admin->id);
    });

    it('open → assigned transition with assigned_to_user_id fires SupportTicketAssigned', function (): void {
        Event::fake([SupportTicketAssigned::class]);
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Open->value,
            'first_response_at' => null,
        ]);
        $assignee = User::factory()->admin(UserRole::Support->value)->create();

        $updated = $this->service->update($ticket, [
            'status' => 'assigned',
            'assigned_to_user_id' => $assignee->id,
        ], $admin);

        expect($updated->status)->toBe(SupportTicketStatus::Assigned)
            ->and($updated->assigned_to_user_id)->toBe($assignee->id);
        expect($updated->first_response_at)->not->toBeNull();
        Event::assertDispatched(SupportTicketAssigned::class);
    });

    it('assigned → in_progress fires SupportTicketInProgress', function (): void {
        Event::fake([SupportTicketInProgress::class]);
        $assignee = User::factory()->admin(UserRole::Support->value)->create();
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Assigned->value,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $updated = $this->service->update($ticket, ['status' => 'in_progress'], $admin);

        expect($updated->status)->toBe(SupportTicketStatus::InProgress);
        Event::assertDispatched(SupportTicketInProgress::class);
    });

    it('in_progress → resolved persists resolution_summary + fires SupportTicketResolved', function (): void {
        Event::fake([SupportTicketResolved::class]);
        $assignee = User::factory()->admin(UserRole::Support->value)->create();
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::InProgress->value,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $updated = $this->service->update($ticket, [
            'status' => 'resolved',
            'resolution_summary' => 'Replaced the filter cartridge.',
        ], $admin);

        expect($updated->status)->toBe(SupportTicketStatus::Resolved)
            ->and($updated->resolution_summary)->toBe('Replaced the filter cartridge.');
        Event::assertDispatched(SupportTicketResolved::class);
    });

    it('resolved → closed stamps closed_at + fires SupportTicketClosed', function (): void {
        Event::fake([SupportTicketClosed::class]);
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Resolved->value,
            'resolution_summary' => 'Already resolved.',
        ]);

        $updated = $this->service->update($ticket, ['status' => 'closed'], $admin);

        expect($updated->status)->toBe(SupportTicketStatus::Closed)
            ->and($updated->closed_at)->not->toBeNull();
        Event::assertDispatched(SupportTicketClosed::class);
    });

    it('rejects transition from closed (terminal) with InvalidSupportTransitionException', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $this->service->update($ticket, ['status' => 'in_progress'], $admin);
    })->throws(InvalidSupportTransitionException::class);

    it('rejects updates on a trashed ticket with TicketTerminalException', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture();
        $ticket->delete();

        $this->service->update($ticket, ['priority' => 'low'], $admin);
    })->throws(TicketTerminalException::class);

    it('combination payload (priority + status) applies both atomically', function (): void {
        $assignee = User::factory()->admin(UserRole::Support->value)->create();
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Open->value,
            'priority' => SupportTicketPriority::Medium->value,
        ]);

        $updated = $this->service->update($ticket, [
            'priority' => 'high',
            'status' => 'assigned',
            'assigned_to_user_id' => $assignee->id,
        ], $admin);

        expect($updated->priority)->toBe(SupportTicketPriority::High)
            ->and($updated->status)->toBe(SupportTicketStatus::Assigned)
            ->and($updated->assigned_to_user_id)->toBe($assignee->id);
    });
});

describe('AdminSupportTicketService::reply()', function (): void {
    it('posts a non-internal reply and stamps first_response_at + last_message_at', function (): void {
        Event::fake([SupportTicketReplied::class]);
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Open->value,
            'first_response_at' => null,
        ]);

        $updated = $this->service->reply($ticket, [
            'body' => 'Customer-visible reply.',
        ], $admin);

        expect($updated->first_response_at)->not->toBeNull()
            ->and($updated->last_message_at)->not->toBeNull();

        $latest = SupportTicketMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->latest('id')->first();
        expect($latest->sender_type)->toBe(SupportTicketMessageSenderType::Admin)
            ->and((bool) $latest->is_internal_note)->toBeFalse();

        Event::assertDispatched(SupportTicketReplied::class);
    });

    it('posts an internal note without stamping first_response_at', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Open->value,
            'first_response_at' => null,
        ]);

        $updated = $this->service->reply($ticket, [
            'body' => 'Internal annotation.',
            'is_internal' => true,
        ], $admin);

        expect($updated->first_response_at)->toBeNull();

        $latest = SupportTicketMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->latest('id')->first();
        expect((bool) $latest->is_internal_note)->toBeTrue();
    });

    it('rejects non-internal reply on a closed ticket', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $this->service->reply($ticket, ['body' => 'cannot reply'], $admin);
    })->throws(TicketTerminalException::class);

    it('allows internal note on a cancelled ticket (audit annotation)', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Duplicate.',
        ]);

        $updated = $this->service->reply($ticket, [
            'body' => 'Post-cancellation annotation.',
            'is_internal' => true,
        ], $admin);

        expect($updated->id)->toBe($ticket->id);
        $latest = SupportTicketMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->latest('id')->first();
        expect((bool) $latest->is_internal_note)->toBeTrue();
    });

    it('rejects reply on a trashed ticket with TicketTerminalException', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture();
        $ticket->delete();

        $this->service->reply($ticket, ['body' => 'trashed'], $admin);
    })->throws(TicketTerminalException::class);

    it('writes an activity log row capturing the reply', function (): void {
        ['admin' => $admin, 'ticket' => $ticket] = svcSupportFixture([
            'status' => SupportTicketStatus::Open->value,
        ]);

        $this->service->reply($ticket, ['body' => 'tracked'], $admin, '10.0.0.1');

        $activity = Activity::query()
            ->where('log_name', 'admin.support')
            ->where('event', 'admin.support.replied')
            ->where('subject_id', $ticket->id)
            ->latest('id')->first();
        expect($activity)->not->toBeNull()
            ->and($activity->causer_id)->toBe($admin->id);
        $props = $activity->properties->all();
        expect($props['is_internal'] ?? null)->toBeFalse();
    });
});
