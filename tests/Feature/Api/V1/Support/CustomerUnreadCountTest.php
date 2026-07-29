<?php

declare(strict_types=1);

use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Admin\AdminSupportTicketService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Customer unread-count cross-check (Phase 12 + Phase 19 wired together)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-support.md § 8.1 / § 8.2 — the
| Phase 19 admin reply increments the customer's unread_message_count;
| the customer's GET /api/v1/support-tickets/{id} stamps
| last_customer_view_at which resets the counter on the next list call.
| Internal notes (admin-only) MUST NOT increment the count.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{user: User, token: string}
 */
function unreadCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

describe('customer unread_message_count cross-check', function (): void {
    it('increments to 1 when admin posts a non-internal reply', function (): void {
        ['user' => $customer, 'token' => $token] = unreadCustomer();
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $service = app(AdminSupportTicketService::class);

        // Customer creates a ticket via factory + initial message.
        $ticket = SupportTicket::factory()->forUser($customer)
            ->state(['status' => SupportTicketStatus::Open->value])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($customer)->create();

        // Admin replies (non-internal).
        $service->reply($ticket, [
            'body' => 'Customer-visible reply.',
        ], $admin);

        // Customer list — unread_message_count should be 1.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $ticket->id);
        expect($row)->not->toBeNull()
            ->and((int) $row['unread_message_count'])->toBe(1);
    });

    it('customer detail view stamps last_customer_view_at and zeroes the unread count', function (): void {
        ['user' => $customer, 'token' => $token] = unreadCustomer();
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $service = app(AdminSupportTicketService::class);

        $ticket = SupportTicket::factory()->forUser($customer)
            ->state(['status' => SupportTicketStatus::Open->value])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($customer)->create();
        $service->reply($ticket, ['body' => 'Customer-visible reply.'], $admin);

        // Pre-view: unread is 1.
        $listBefore = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');
        $rowBefore = collect($listBefore->json('data'))->firstWhere('id', $ticket->id);
        expect((int) $rowBefore['unread_message_count'])->toBe(1);

        // Customer views detail.
        $detail = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/support-tickets/{$ticket->id}");
        $detail->assertOk();

        // The view marker MUST have been stamped.
        $ticket->refresh();
        expect($ticket->last_customer_view_at)->not->toBeNull();

        // Post-view list: unread back to 0.
        $listAfter = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');
        $rowAfter = collect($listAfter->json('data'))->firstWhere('id', $ticket->id);
        expect((int) $rowAfter['unread_message_count'])->toBe(0);
    });

    it('a subsequent admin reply re-raises the unread count to 1', function (): void {
        ['user' => $customer, 'token' => $token] = unreadCustomer();
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $service = app(AdminSupportTicketService::class);

        $ticket = SupportTicket::factory()->forUser($customer)
            ->state(['status' => SupportTicketStatus::Open->value])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($customer)->create();
        $service->reply($ticket, ['body' => 'First admin reply.'], $admin);

        // Customer views, then the count zeroes.
        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/support-tickets/{$ticket->id}")->assertOk();

        // Wait a beat so the new admin message timestamp strictly
        // exceeds the customer's view marker (subsecond MySQL behaviour
        // truncates timestamps to whole seconds).
        Carbon::setTestNow(now()->addSeconds(2));
        $service->reply($ticket, ['body' => 'Second admin reply.'], $admin);
        Carbon::setTestNow();

        $list = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');
        $row = collect($list->json('data'))->firstWhere('id', $ticket->id);
        expect((int) $row['unread_message_count'])->toBe(1);
    });

    it('internal admin reply does NOT increment unread_message_count', function (): void {
        ['user' => $customer, 'token' => $token] = unreadCustomer();
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $service = app(AdminSupportTicketService::class);

        $ticket = SupportTicket::factory()->forUser($customer)
            ->state(['status' => SupportTicketStatus::Open->value])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($customer)->create();

        // Internal note ONLY.
        $service->reply($ticket, [
            'body' => 'Internal-only note — customer must not see this.',
            'is_internal' => true,
        ], $admin);

        $list = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');
        $row = collect($list->json('data'))->firstWhere('id', $ticket->id);
        expect((int) $row['unread_message_count'])->toBe(0);
    });

    it('customer NEVER sees the body of an internal admin note on the detail view', function (): void {
        ['user' => $customer, 'token' => $token] = unreadCustomer();
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $service = app(AdminSupportTicketService::class);

        $ticket = SupportTicket::factory()->forUser($customer)
            ->state(['status' => SupportTicketStatus::Open->value])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($customer)->create();

        $service->reply($ticket, [
            'body' => 'TOP-SECRET-INTERNAL-NOTE-MARKER-CUSTOMER-MUST-NEVER-SEE',
            'is_internal' => true,
        ], $admin);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/support-tickets/{$ticket->id}");

        $response->assertOk();
        expect(json_encode($response->json('data')))
            ->not->toContain('TOP-SECRET-INTERNAL-NOTE-MARKER-CUSTOMER-MUST-NEVER-SEE');
    });
});
