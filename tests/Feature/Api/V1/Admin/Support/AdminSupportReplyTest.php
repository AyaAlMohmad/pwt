<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\SupportTicketMessageSenderType;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Events\Support\SupportTicketReplied;
use App\Models\File;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| POST /api/v1/admin/support-tickets/{id}/reply
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-support.md § 3.4 / § 5.4. Customer-
| visible replies stamp first_response_at + last_message_at and fire
| SupportTicketReplied. Internal notes do NOT stamp first_response_at.
| Non-internal replies on terminal tickets are rejected with 409
| ERR_TICKET_TERMINAL.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function replyAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function replyTicket(array $overrides = []): SupportTicket
{
    $customer = User::factory()->customer()->create();

    return SupportTicket::factory()->forUser($customer)->state($overrides)->create();
}

describe('POST /api/v1/admin/support-tickets/{id}/reply', function (): void {
    it('posts a customer-visible reply and stamps first_response_at + last_message_at', function (): void {
        Event::fake([SupportTicketReplied::class]);
        [$admin, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket([
            'status' => SupportTicketStatus::Open->value,
            'first_response_at' => null,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'Thanks for the update — we are looking into it.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.id', $ticket->id);

        $latestMessage = SupportTicketMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->latest('id')->first();
        expect($latestMessage)->not->toBeNull()
            ->and($latestMessage->sender_type)->toBe(SupportTicketMessageSenderType::Admin)
            ->and($latestMessage->sender_user_id)->toBe($admin->id)
            ->and((bool) $latestMessage->is_internal_note)->toBeFalse();

        $ticket->refresh();
        expect($ticket->first_response_at)->not->toBeNull()
            ->and($ticket->last_message_at)->not->toBeNull();

        Event::assertDispatched(SupportTicketReplied::class);
    });

    it('posts an internal note (is_internal=true) and does NOT stamp first_response_at', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket([
            'status' => SupportTicketStatus::Open->value,
            'first_response_at' => null,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'Note to other admins — escalate to engineering.',
                'is_internal' => true,
            ]);

        $response->assertStatus(201);

        $latest = SupportTicketMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->latest('id')->first();
        expect((bool) $latest->is_internal_note)->toBeTrue();

        // first_response_at is NOT stamped by internal-only reply.
        $ticket->refresh();
        expect($ticket->first_response_at)->toBeNull();
        // last_message_at IS updated regardless.
        expect($ticket->last_message_at)->not->toBeNull();
    });

    it('accepts up to 5 attachment_file_ids owned by the admin', function (): void {
        [$admin, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);
        $file = File::factory()->for($admin)->forType(FileType::SupportAttachment)->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'See attached invoice.',
                'attachment_file_ids' => [$file->id],
            ]);

        $response->assertStatus(201);

        $latest = SupportTicketMessage::query()
            ->where('support_ticket_id', $ticket->id)
            ->latest('id')->first();
        $this->assertDatabaseHas('support_ticket_attachments', [
            'support_ticket_id' => $ticket->id,
            'support_ticket_message_id' => $latest->id,
            'file_id' => $file->id,
            'uploaded_by_user_id' => $admin->id,
        ]);
    });

    it('rejects attachments owned by a different user with 422', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $other = User::factory()->customer()->create();
        $foreignFile = File::factory()->for($other)->forType(FileType::SupportAttachment)->create();
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'attempting to attach someone else file',
                'attachment_file_ids' => [$foreignFile->id],
            ]);

        $response->assertStatus(422);
    });

    it('rejects more than 5 attachments with 422', function (): void {
        [$admin, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);
        $files = File::factory()->count(6)->for($admin)
            ->forType(FileType::SupportAttachment)->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'too many files',
                'attachment_file_ids' => $files->pluck('id')->all(),
            ]);

        $response->assertStatus(422);
    });

    it('allows is_internal=true on a resolved ticket (internal notes permitted on terminal)', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket([
            'status' => SupportTicketStatus::Resolved->value,
            'resolution_summary' => 'Already resolved.',
            'first_response_at' => now()->subHours(2),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'Audit note — customer thanked us via email.',
                'is_internal' => true,
            ]);

        $response->assertStatus(201);
    });

    it('allows is_internal=true on a closed ticket (audit annotation)', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket([
            'status' => SupportTicketStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'Annotating after close.',
                'is_internal' => true,
            ]);

        $response->assertStatus(201);
    });

    it('rejects customer-visible reply on a closed ticket with 409 ERR_TICKET_TERMINAL', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket([
            'status' => SupportTicketStatus::Closed->value,
            'closed_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'Trying to reply on a closed ticket.',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_TICKET_TERMINAL');
    });

    it('rejects customer-visible reply on a cancelled ticket with 409 ERR_TICKET_TERMINAL', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket([
            'status' => SupportTicketStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Duplicate ticket.',
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'Trying to reply on a cancelled ticket.',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_TICKET_TERMINAL');
    });

    it('returns 403 for operations role', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Operations->value);
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'ops attempting to reply',
            ]);

        $response->assertStatus(403);
    });

    it('returns 403 for sales role', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Sales->value);
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'sales attempting to reply',
            ]);

        $response->assertStatus(403);
    });

    it('returns 403 for a customer token (cannot access admin endpoint)', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => 'customer attempting to reply via admin endpoint',
            ]);

        $response->assertStatus(403);
    });

    it('returns 401 without a Bearer token', function (): void {
        $ticket = replyTicket();

        $response = $this->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
            'body' => 'no auth',
        ]);

        $response->assertStatus(401);
    });

    it('rejects empty body (after trim) with 422', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", [
                'body' => '   ',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['body']]);
    });

    it('rejects missing body with 422', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);
        $ticket = replyTicket(['status' => SupportTicketStatus::Open->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/support-tickets/{$ticket->id}/reply", []);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['body']]);
    });

    it('returns 404 for a non-existent ticket id', function (): void {
        [, $bearer] = replyAdminBearer(UserRole::Support->value);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/support-tickets/9999999/reply', [
                'body' => 'no such ticket',
            ]);

        $response->assertStatus(404);
    });
});
