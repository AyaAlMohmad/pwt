<?php

declare(strict_types=1);

use App\Enums\SupportTicketStatus;
use App\Events\Support\SupportTicketCancelled;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| POST /api/v1/support-tickets/{id}/cancel
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/support.md § 3, § 4.3, § 5.4, § 10.4.
| Customer cancel is allowed only from `open`. Anything else → 409
| ERR_INVALID_STATUS_TRANSITION. Ownership → 403. Not found → 404.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @return array{user: User, token: string}
 */
function makeSupportCancelCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/*
|--------------------------------------------------------------------------
| Happy path — cancel from open
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets/{id}/cancel — allowed', function (): void {
    it('cancels a ticket in `open` state and writes a status_history row', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
                'cancellation_reason' => 'No longer need help with this issue.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.status', SupportTicketStatus::Cancelled->value);

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => SupportTicketStatus::Cancelled->value,
            'cancelled_by_user_id' => $user->id,
            'cancellation_reason' => 'No longer need help with this issue.',
        ]);

        $this->assertDatabaseHas('support_ticket_status_history', [
            'support_ticket_id' => $ticket->id,
            'from_status' => SupportTicketStatus::Open->value,
            'to_status' => SupportTicketStatus::Cancelled->value,
            'actor_type' => 'customer',
            'actor_user_id' => $user->id,
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Forbidden state transitions → 409
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets/{id}/cancel — forbidden transitions', function (): void {
    it('returns 409 ERR_INVALID_STATUS_TRANSITION from `assigned`', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $admin = User::factory()->admin()->create();
        $ticket = SupportTicket::factory()->forUser($user)->assigned($admin)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => SupportTicketStatus::Assigned->value,
        ]);
    });

    it('returns 409 from `in_progress`', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $admin = User::factory()->admin()->create();
        $ticket = SupportTicket::factory()->forUser($user)->inProgress($admin)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 from `resolved`', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $admin = User::factory()->admin()->create();
        $ticket = SupportTicket::factory()->forUser($user)->resolved($admin)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 from `closed`', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $ticket = SupportTicket::factory()->forUser($user)->closed()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 from `cancelled` (already terminal)', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $ticket = SupportTicket::factory()->forUser($user)->cancelled()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });
});

/*
|--------------------------------------------------------------------------
| Auth / ownership / validation / not-found
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets/{id}/cancel — auth & validation', function (): void {
    it('returns 403 ERR_FORBIDDEN when cancelling another customer\'s ticket', function (): void {
        ['token' => $token] = makeSupportCancelCustomer();

        $stranger = User::factory()->customer()->create();
        $strangerTicket = SupportTicket::factory()->forUser($stranger)->open()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$strangerTicket->id}/cancel", [
                'cancellation_reason' => 'Not mine to cancel',
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'ERR_FORBIDDEN');

        $this->assertDatabaseHas('support_tickets', [
            'id' => $strangerTicket->id,
            'status' => SupportTicketStatus::Open->value,
        ]);
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        ['token' => $token] = makeSupportCancelCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets/9999999/cancel', [
                'cancellation_reason' => 'Ghost ticket',
            ]);

        $response->assertStatus(404)->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 422 ERR_VALIDATION when cancellation_reason is missing', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['cancellation_reason']]);

        // Ticket must remain open — no side-effects on validation failure.
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'status' => SupportTicketStatus::Open->value,
        ]);
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        ['user' => $user] = makeSupportCancelCustomer();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $response = $this->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
            'cancellation_reason' => 'No token',
        ]);

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

/*
|--------------------------------------------------------------------------
| Event dispatch
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets/{id}/cancel — events', function (): void {
    it('dispatches SupportTicketCancelled after commit', function (): void {
        Event::fake([SupportTicketCancelled::class]);

        ['user' => $user, 'token' => $token] = makeSupportCancelCustomer();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/support-tickets/{$ticket->id}/cancel", [
                'cancellation_reason' => 'Resolved offline through chat.',
            ]);

        $response->assertOk();

        Event::assertDispatched(
            SupportTicketCancelled::class,
            fn (SupportTicketCancelled $e): bool => $e->supportTicketId === $ticket->id
                && $e->userId === $user->id
                && $e->reason === 'Resolved offline through chat.',
        );
    });
});
