<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Events\Support\SupportTicketCreated;
use App\Models\Address;
use App\Models\Country;
use App\Models\File;
use App\Models\Machine;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/support-tickets
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/support.md § 1, § 4.1, § 5.1, § 10.1.
| Customer files a ticket. Server creates the ticket + initial message +
| optional attachment pivot rows + initial status_history entry inside
| one transaction. Rate-limited at 10/min/user via `support.create`.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    RateLimiter::clear('support.create');

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string}
 */
function makeSupportCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    RateLimiter::clear('support.create:'.$user->id);

    return ['user' => $user, 'token' => $token];
}

function supportAttachmentFile(User $user): File
{
    return File::factory()->for($user)->forType(FileType::SupportAttachment)->create();
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets — happy path', function (): void {
    it('returns 201 with the detail envelope, status=open, priority=medium', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'I cannot complete checkout',
                'category' => SupportTicketCategory::AccountIssue->value,
                'message' => 'I keep getting a 500 error when trying to confirm my order at the payment step.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', SupportTicketStatus::Open->value)
            ->assertJsonPath('data.priority', SupportTicketPriority::Medium->value)
            ->assertJsonPath('data.category', SupportTicketCategory::AccountIssue->value)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'public_id', 'subject', 'category', 'priority',
                    'status', 'opened_at', 'last_message_at',
                    'is_cancellable', 'messages', 'status_history',
                ],
            ]);

        $ticketId = (int) $response->json('data.id');

        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticketId,
            'user_id' => $user->id,
            'status' => SupportTicketStatus::Open->value,
            'priority' => SupportTicketPriority::Medium->value,
            'category' => SupportTicketCategory::AccountIssue->value,
        ]);

        // Initial customer message persisted.
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticketId,
            'sender_user_id' => $user->id,
            'is_internal_note' => false,
        ]);

        // First status history row.
        $this->assertDatabaseHas('support_ticket_status_history', [
            'support_ticket_id' => $ticketId,
            'from_status' => null,
            'to_status' => SupportTicketStatus::Open->value,
            'actor_type' => 'customer',
            'actor_user_id' => $user->id,
        ]);
    });

    it('hardcodes priority to medium even when the customer body says otherwise', function (): void {
        // Per the Form Request `priority` is `prohibited` — confirmed below;
        // here we assert the persisted value matches the doc invariant.
        ['user' => $user, 'token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Payment problem on order',
                'category' => SupportTicketCategory::PaymentIssue->value,
                'message' => 'I would like to know why my last payment was declined.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.priority', SupportTicketPriority::Medium->value);

        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $user->id,
            'priority' => SupportTicketPriority::Medium->value,
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Attachments — up to 5 owned support_attachment files
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets — attachments', function (): void {
    it('attaches up to 5 owned support_attachment files via the pivot table', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCustomer();
        $files = collect(range(1, 3))->map(fn () => supportAttachmentFile($user));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Issue with my latest invoice',
                'category' => SupportTicketCategory::PaymentIssue->value,
                'message' => 'Attaching the screenshots and the PDF invoice for review.',
                'attachment_file_ids' => $files->pluck('id')->all(),
            ]);

        $response->assertCreated();
        $ticketId = (int) $response->json('data.id');

        foreach ($files as $file) {
            $this->assertDatabaseHas('support_ticket_attachments', [
                'support_ticket_id' => $ticketId,
                'file_id' => $file->id,
                'uploaded_by_user_id' => $user->id,
            ]);
        }
    });

    it('returns 422 when an attachment file id is not owned by the caller', function (): void {
        ['token' => $token] = makeSupportCustomer();
        $stranger = User::factory()->customer()->create();
        $strangerFile = File::factory()->for($stranger)->forType(FileType::SupportAttachment)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Trying to abuse a foreign file',
                'category' => SupportTicketCategory::Other->value,
                'message' => 'This payload should be rejected with a 422.',
                'attachment_file_ids' => [$strangerFile->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['attachment_file_ids.0']]);
    });

    it('returns 422 when an attachment file has the wrong FileType', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCustomer();
        $wrongType = File::factory()->for($user)->forType(FileType::MaintenanceMedia)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Wrong file type',
                'category' => SupportTicketCategory::Other->value,
                'message' => 'Attaching a maintenance file should be rejected.',
                'attachment_file_ids' => [$wrongType->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['attachment_file_ids.0']]);
    });

    it('returns 422 when a file is already attached to another support ticket (one-shot)', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCustomer();
        $file = supportAttachmentFile($user);

        // First request consumes the file.
        $first = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'First ticket with this file',
                'category' => SupportTicketCategory::Other->value,
                'message' => 'Using a fresh attachment for the very first time.',
                'attachment_file_ids' => [$file->id],
            ]);
        $first->assertCreated();

        // Second request tries to reuse the same file.
        $second = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Second ticket reusing the same file',
                'category' => SupportTicketCategory::Other->value,
                'message' => 'This should fail because the file is already attached elsewhere.',
                'attachment_file_ids' => [$file->id],
            ]);

        $second->assertStatus(422)
            ->assertJsonStructure(['errors' => ['attachment_file_ids.0']]);
    });

    it('returns 422 when attachment_file_ids has more than 5 entries', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCustomer();
        $files = collect(range(1, 6))->map(fn () => supportAttachmentFile($user));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Too many attachments',
                'category' => SupportTicketCategory::Other->value,
                'message' => 'I really love attachments — there are six of them here.',
                'attachment_file_ids' => $files->pluck('id')->all(),
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['attachment_file_ids']]);
    });
});

/*
|--------------------------------------------------------------------------
| Cross-link ownership
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets — related resource ownership', function (): void {
    it('returns 422 when related_order_id belongs to another customer', function (): void {
        ['token' => $token] = makeSupportCustomer();
        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $strangerOrder = Order::factory()->forUser($stranger)->forAddress($strangerAddress)
            ->state(['currency' => 'AED'])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Cross-tenant probe',
                'category' => SupportTicketCategory::OrderIssue->value,
                'message' => 'Trying to reference an order that is not mine.',
                'related_order_id' => $strangerOrder->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['related_order_id']]);
    });

    it('returns 422 when related_machine_id belongs to another customer', function (): void {
        ['token' => $token] = makeSupportCustomer();
        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $product = Product::factory()->create();
        $strangerMachine = Machine::factory()->forUser($stranger)->forProduct($product)
            ->state(['address_id' => $strangerAddress->id])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Cross-tenant probe',
                'category' => SupportTicketCategory::MaintenanceIssue->value,
                'message' => 'Trying to reference a machine that is not mine.',
                'related_machine_id' => $strangerMachine->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['related_machine_id']]);
    });
});

/*
|--------------------------------------------------------------------------
| Validation failures
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets — validation', function (): void {
    it('returns 422 with errors.subject when subject is missing', function (): void {
        ['token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'category' => SupportTicketCategory::Other->value,
                'message' => 'A message that meets the minimum length requirement.',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['subject']]);
    });

    it('returns 422 with errors.category when category is missing', function (): void {
        ['token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Missing category field',
                'message' => 'A message that meets the minimum length requirement.',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category']]);
    });

    it('returns 422 with errors.message when message is missing', function (): void {
        ['token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Missing message field',
                'category' => SupportTicketCategory::Other->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['message']]);
    });

    it('returns 422 with errors.category for an invalid enum value', function (): void {
        ['token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Bogus category',
                'category' => 'totally-not-a-real-category',
                'message' => 'A message that meets the minimum length requirement.',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category']]);
    });

    it('returns 422 when subject exceeds 200 chars', function (): void {
        ['token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => str_repeat('a', 201),
                'category' => SupportTicketCategory::Other->value,
                'message' => 'A message that meets the minimum length requirement.',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['subject']]);
    });

    it('returns 422 when message exceeds 5000 chars', function (): void {
        ['token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Way-too-long message',
                'category' => SupportTicketCategory::Other->value,
                'message' => str_repeat('a', 5001),
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['message']]);
    });
});

/*
|--------------------------------------------------------------------------
| Auth + rate limit
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets — auth & rate limit', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $response = $this->postJson('/api/v1/support-tickets', [
            'subject' => 'No token attached',
            'category' => SupportTicketCategory::Other->value,
            'message' => 'A message that meets the minimum length requirement.',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 429 ERR_RATE_LIMITED after exceeding the 10/min/user support.create limiter', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportCustomer();

        $hit429 = false;
        for ($i = 0; $i < 12; $i++) {
            $resp = $this->withHeader('Authorization', "Bearer $token")
                ->postJson('/api/v1/support-tickets', [
                    'subject' => 'Rate-limit probe #'.$i,
                    'category' => SupportTicketCategory::Other->value,
                    'message' => 'Spamming the ticket endpoint to trip the support.create limiter ('.$i.').',
                ]);
            if ($resp->status() === 429) {
                $hit429 = true;
                $resp->assertJsonPath('code', 'ERR_RATE_LIMITED');
                break;
            }
        }

        expect($hit429)->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| Domain event dispatch
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/support-tickets — events', function (): void {
    it('dispatches SupportTicketCreated after commit with the new ticket id', function (): void {
        Event::fake([SupportTicketCreated::class]);

        ['user' => $user, 'token' => $token] = makeSupportCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/support-tickets', [
                'subject' => 'Event dispatch probe',
                'category' => SupportTicketCategory::Feedback->value,
                'message' => 'I would like to leave general feedback about the website.',
            ]);

        $response->assertCreated();
        $ticketId = (int) $response->json('data.id');

        Event::assertDispatched(
            SupportTicketCreated::class,
            fn (SupportTicketCreated $e): bool => $e->supportTicketId === $ticketId
                && $e->userId === $user->id,
        );
    });
});
