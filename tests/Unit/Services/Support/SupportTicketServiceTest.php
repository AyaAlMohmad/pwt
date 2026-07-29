<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketMessageSenderType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Events\Support\SupportTicketCancelled;
use App\Events\Support\SupportTicketCreated;
use App\Exceptions\Support\InvalidSupportTransitionException;
use App\Models\Address;
use App\Models\Country;
use App\Models\File;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| SupportTicketService — service-level tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/support.md § 7. Drives:
|   - submit() — persists ticket + initial customer message + attachment
|                pivots + first status_history row + fires
|                SupportTicketCreated after commit.
|   - listForUser() — paginates with status / category filters.
|   - findForUser() — eager-loads thread (internal notes excluded) +
|                     status_history + related summaries.
|   - cancel()  — open-only; writes history; fires SupportTicketCancelled.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->service = new SupportTicketService;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| submit()
|--------------------------------------------------------------------------
*/

describe('SupportTicketService::submit()', function (): void {
    it('persists ticket, initial message, attachment pivots, and the first status_history row', function (): void {
        $user = User::factory()->customer()->create();
        $file1 = File::factory()->for($user)->forType(FileType::SupportAttachment)->create();
        $file2 = File::factory()->for($user)->forType(FileType::SupportAttachment)->create();

        $ticket = $this->service->submit($user, [
            'subject' => 'Initial submission probe',
            'category' => SupportTicketCategory::AccountIssue->value,
            'message' => 'I cannot reset my password and the help page is not loading.',
            'attachment_file_ids' => [$file1->id, $file2->id],
        ]);

        expect($ticket->user_id)->toBe($user->id);
        expect($ticket->status)->toBe(SupportTicketStatus::Open);
        expect($ticket->priority)->toBe(SupportTicketPriority::Medium);
        expect($ticket->category)->toBe(SupportTicketCategory::AccountIssue);
        expect($ticket->subject)->toBe('Initial submission probe');
        expect($ticket->public_id)->not->toBeEmpty();

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_user_id' => $user->id,
            'sender_type' => SupportTicketMessageSenderType::Customer->value,
            'is_internal_note' => false,
        ]);

        $this->assertDatabaseHas('support_ticket_attachments', [
            'support_ticket_id' => $ticket->id,
            'file_id' => $file1->id,
            'uploaded_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('support_ticket_attachments', [
            'support_ticket_id' => $ticket->id,
            'file_id' => $file2->id,
        ]);

        $this->assertDatabaseHas('support_ticket_status_history', [
            'support_ticket_id' => $ticket->id,
            'from_status' => null,
            'to_status' => SupportTicketStatus::Open->value,
            'actor_type' => 'customer',
            'actor_user_id' => $user->id,
        ]);
    });

    it('fires SupportTicketCreated after commit with the persisted ticket id and user id', function (): void {
        Event::fake([SupportTicketCreated::class]);
        $user = User::factory()->customer()->create();

        $ticket = $this->service->submit($user, [
            'subject' => 'Event probe',
            'category' => SupportTicketCategory::Feedback->value,
            'message' => 'Just verifying that the domain event fires after commit.',
        ]);

        Event::assertDispatched(
            SupportTicketCreated::class,
            fn (SupportTicketCreated $e): bool => $e->supportTicketId === $ticket->id
                && $e->userId === $user->id
                && $e->actorUserId === $user->id,
        );
    });

    it('persists related_order_id and related_machine_id when supplied (validation enforces ownership upstream)', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = Order::factory()->forUser($user)->forAddress($address)
            ->state(['currency' => 'AED'])->create();

        $ticket = $this->service->submit($user, [
            'subject' => 'Order question',
            'category' => SupportTicketCategory::OrderIssue->value,
            'message' => 'I have a question about my latest order delivery time.',
            'related_order_id' => $order->id,
        ]);

        expect($ticket->related_order_id)->toBe($order->id);
    });
});

/*
|--------------------------------------------------------------------------
| listForUser()
|--------------------------------------------------------------------------
*/

describe('SupportTicketService::listForUser()', function (): void {
    it('returns only the caller\'s tickets', function (): void {
        $user = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();

        SupportTicket::factory()->forUser($user)->open()->count(2)->create();
        SupportTicket::factory()->forUser($stranger)->open()->create();

        $page = $this->service->listForUser($user, [], 1, 15);

        expect($page->total())->toBe(2);
    });

    it('filters by status', function (): void {
        $user = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($user)->open()->create();
        SupportTicket::factory()->forUser($user)->cancelled()->create();

        $page = $this->service->listForUser($user, ['status' => 'cancelled'], 1, 15);

        expect($page->total())->toBe(1);
        expect($page->items()[0]->status)->toBe(SupportTicketStatus::Cancelled);
    });

    it('filters by category', function (): void {
        $user = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($user)->open()
            ->withCategory(SupportTicketCategory::OrderIssue)->create();
        SupportTicket::factory()->forUser($user)->open()
            ->withCategory(SupportTicketCategory::Feedback)->create();

        $page = $this->service->listForUser($user, ['category' => 'feedback'], 1, 15);

        expect($page->total())->toBe(1);
        expect($page->items()[0]->category)->toBe(SupportTicketCategory::Feedback);
    });
});

/*
|--------------------------------------------------------------------------
| findForUser()
|--------------------------------------------------------------------------
*/

describe('SupportTicketService::findForUser()', function (): void {
    it('eager-loads the thread but excludes internal-note rows', function (): void {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();

        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($user)
            ->state(['body' => 'Customer-visible'])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->internalNote($admin)
            ->state(['body' => 'Internal-note-marker'])->create();

        $loaded = $this->service->findForUser($ticket, $user);

        $bodies = $loaded->messages->pluck('body')->all();
        expect($bodies)->toContain('Customer-visible');
        expect($bodies)->not->toContain('Internal-note-marker');
    });

    it('aborts 403 when called with a stranger user (defence-in-depth)', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($owner)->open()->create();

        $thrown = null;
        try {
            $this->service->findForUser($ticket, $stranger);
        } catch (HttpException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull();
        expect($thrown->getStatusCode())->toBe(403);
    });
});

/*
|--------------------------------------------------------------------------
| cancel()
|--------------------------------------------------------------------------
*/

describe('SupportTicketService::cancel()', function (): void {
    it('cancels an open ticket, writes a status_history row, and fires SupportTicketCancelled', function (): void {
        Event::fake([SupportTicketCancelled::class]);
        $user = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $cancelled = $this->service->cancel($ticket, $user, 'Found the answer in the FAQ.');

        expect($cancelled->status)->toBe(SupportTicketStatus::Cancelled);
        expect($cancelled->cancelled_by_user_id)->toBe($user->id);
        expect($cancelled->cancellation_reason)->toBe('Found the answer in the FAQ.');
        expect($cancelled->cancelled_at)->not->toBeNull();

        $this->assertDatabaseHas('support_ticket_status_history', [
            'support_ticket_id' => $ticket->id,
            'from_status' => SupportTicketStatus::Open->value,
            'to_status' => SupportTicketStatus::Cancelled->value,
            'actor_type' => 'customer',
            'actor_user_id' => $user->id,
        ]);

        Event::assertDispatched(
            SupportTicketCancelled::class,
            fn (SupportTicketCancelled $e): bool => $e->supportTicketId === $ticket->id
                && $e->userId === $user->id
                && $e->reason === 'Found the answer in the FAQ.',
        );
    });

    it('throws InvalidSupportTransitionException when ticket is not open (assigned)', function (): void {
        $user = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $ticket = SupportTicket::factory()->forUser($user)->assigned($admin)->create();

        $this->service->cancel($ticket, $user, 'Trying anyway');
    })->throws(InvalidSupportTransitionException::class);

    it('throws InvalidSupportTransitionException when ticket is already cancelled', function (): void {
        $user = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($user)->cancelled()->create();

        $this->service->cancel($ticket, $user, 'Trying anyway');
    })->throws(InvalidSupportTransitionException::class);

    it('throws InvalidSupportTransitionException when ticket is closed', function (): void {
        $user = User::factory()->customer()->create();
        $ticket = SupportTicket::factory()->forUser($user)->closed()->create();

        $this->service->cancel($ticket, $user, 'Trying anyway');
    })->throws(InvalidSupportTransitionException::class);
});
