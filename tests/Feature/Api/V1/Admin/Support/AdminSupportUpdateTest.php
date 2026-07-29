<?php

declare(strict_types=1);

use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Events\Support\SupportTicketAssigned;
use App\Events\Support\SupportTicketClosed;
use App\Events\Support\SupportTicketInProgress;
use App\Events\Support\SupportTicketResolved;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| PATCH /api/v1/admin/support-tickets/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-support.md § 3.3 / § 5.3. Drives the
| state machine through SupportTicketService::transitionTo(). Invalid
| transitions surface as 409 ERR_INVALID_STATUS_TRANSITION. Terminal
| sources (closed / cancelled) raise 409 in the same envelope.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function updateAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function updateTicket(array $overrides = []): SupportTicket
{
    $customer = User::factory()->customer()->create();

    return SupportTicket::factory()->forUser($customer)->state($overrides)->create();
}

describe('PATCH /api/v1/admin/support-tickets/{id}', function (): void {
    it('transitions open → assigned and stamps first_response_at + fires event', function (): void {
        Event::fake([SupportTicketAssigned::class]);
        [$admin, $bearer] = updateAdminBearer();
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value, 'first_response_at' => null]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Assigned->value,
                'assigned_to_user_id' => $support->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatus::Assigned->value)
            ->assertJsonPath('data.assigned_to.id', $support->id);

        $ticket->refresh();
        expect($ticket->first_response_at)->not->toBeNull()
            ->and($ticket->assigned_to_user_id)->toBe($support->id);

        Event::assertDispatched(SupportTicketAssigned::class);

        // Activity log row recorded with actor + payload.
        $activity = Activity::query()
            ->where('log_name', 'admin.support')
            ->where('event', 'admin.support.updated')
            ->where('subject_id', $ticket->id)
            ->latest('id')
            ->first();
        expect($activity)->not->toBeNull();
        expect($activity?->causer_id)->toBe($admin->id);
    });

    it('updates priority only and returns 200', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket([
            'status' => SupportTicketStatus::Open->value,
            'priority' => SupportTicketPriority::Medium->value,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'priority' => SupportTicketPriority::Urgent->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.priority', SupportTicketPriority::Urgent->value);

        expect($ticket->fresh()->priority->value)->toBe('urgent');
    });

    it('transitions assigned → in_progress and fires event', function (): void {
        Event::fake([SupportTicketInProgress::class]);
        [, $bearer] = updateAdminBearer();
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $ticket = updateTicket([
            'status' => SupportTicketStatus::Assigned->value,
            'assigned_to_user_id' => $support->id,
            'first_response_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::InProgress->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatus::InProgress->value);

        Event::assertDispatched(SupportTicketInProgress::class);
    });

    it('transitions in_progress → resolved with resolution_summary; fires event', function (): void {
        Event::fake([SupportTicketResolved::class]);
        [, $bearer] = updateAdminBearer();
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $ticket = updateTicket([
            'status' => SupportTicketStatus::InProgress->value,
            'assigned_to_user_id' => $support->id,
            'first_response_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Resolved->value,
                'resolution_summary' => 'Filter swapped, customer happy.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatus::Resolved->value)
            ->assertJsonPath('data.resolution_summary', 'Filter swapped, customer happy.');

        Event::assertDispatched(SupportTicketResolved::class);
    });

    it('transitions resolved → closed and stamps closed_at; fires event', function (): void {
        Event::fake([SupportTicketClosed::class]);
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket([
            'status' => SupportTicketStatus::Resolved->value,
            'resolution_summary' => 'Already resolved.',
            'first_response_at' => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Closed->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatus::Closed->value);

        expect($ticket->fresh()->closed_at)->not->toBeNull();
        Event::assertDispatched(SupportTicketClosed::class);
    });

    it('rejects status changes from closed (terminal) with 409 ERR_INVALID_STATUS_TRANSITION', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket([
            'status' => SupportTicketStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::InProgress->value,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('rejects an invalid transition (open → resolved) with 409 ERR_INVALID_STATUS_TRANSITION', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Resolved->value,
                'resolution_summary' => 'too soon to resolve',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 403 when operations attempts to update (read-only)', function (): void {
        [, $bearer] = updateAdminBearer(UserRole::Operations->value);
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'priority' => SupportTicketPriority::High->value,
            ]);

        $response->assertStatus(403);
    });

    it('returns 403 when sales attempts to update', function (): void {
        [, $bearer] = updateAdminBearer(UserRole::Sales->value);
        $ticket = updateTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'priority' => SupportTicketPriority::Low->value,
            ]);

        $response->assertStatus(403);
    });

    it('rejects assigning a non-support / non-super_admin user with 422', function (): void {
        [, $bearer] = updateAdminBearer();
        $opsUser = User::factory()->admin(UserRole::Operations->value)->create();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Assigned->value,
                'assigned_to_user_id' => $opsUser->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['assigned_to_user_id']]);
    });

    it('rejects assigned_to_user_id pointing at a customer with 422', function (): void {
        [, $bearer] = updateAdminBearer();
        $customer = User::factory()->customer()->create();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Assigned->value,
                'assigned_to_user_id' => $customer->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['assigned_to_user_id']]);
    });

    it('rejects status=open (initial state only) with 422', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => 'open',
            ]);

        $response->assertStatus(422);
    });

    it('rejects status=assigned without assigned_to_user_id with 422', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Assigned->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['assigned_to_user_id']]);
    });

    it('rejects status=resolved without resolution_summary with 422', function (): void {
        [, $bearer] = updateAdminBearer();
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $ticket = updateTicket([
            'status' => SupportTicketStatus::InProgress->value,
            'assigned_to_user_id' => $support->id,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'status' => SupportTicketStatus::Resolved->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['resolution_summary']]);
    });

    it('rejects an empty body with 422', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", []);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['_']]);
    });

    it('rejects prohibited fields (subject, category) with 422', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'priority' => SupportTicketPriority::High->value,
                'subject' => 'attempting to overwrite',
                'category' => 'feedback',
            ]);

        $response->assertStatus(422);
    });

    it('returns 404 for a non-existent ticket id', function (): void {
        [, $bearer] = updateAdminBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson('/api/v1/admin/support-tickets/9999999', [
                'priority' => SupportTicketPriority::Low->value,
            ]);

        $response->assertStatus(404);
    });

    it('returns 401 without a Bearer token', function (): void {
        $ticket = updateTicket();

        $response = $this->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
            'priority' => SupportTicketPriority::Low->value,
        ]);

        $response->assertStatus(401);
    });

    it('rejects modifications on a trashed ticket with 409 ERR_RESOURCE_TRASHED', function (): void {
        [, $bearer] = updateAdminBearer();
        $ticket = updateTicket(['status' => SupportTicketStatus::Open->value]);
        $ticket->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/support-tickets/{$ticket->id}", [
                'priority' => SupportTicketPriority::Low->value,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_RESOURCE_TRASHED');
    });
});
