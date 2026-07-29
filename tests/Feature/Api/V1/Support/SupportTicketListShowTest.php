<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\File;
use App\Models\Machine;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/support-tickets + GET /api/v1/support-tickets/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/support.md § 1, § 4.2, § 5.2, § 5.3, § 10.2,
| § 10.3. Customer-scoped. Internal notes NEVER surface. Sort by
| last_message_at DESC. Assignee is anonymised to first-name only.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string}
 */
function makeSupportListShowCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/support-tickets (list)
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/support-tickets', function (): void {
    it('returns 200 with an empty data array + pagination meta', function (): void {
        ['token' => $token] = makeSupportListShowCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'data',
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total']],
            ])
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    });

    it('lists the caller\'s tickets sorted by last_message_at DESC with pagination meta', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();

        $older = SupportTicket::factory()->forUser($user)->open()
            ->state(['created_at' => now()->subDays(5)])->create();
        SupportTicketMessage::factory()->forTicket($older)->fromCustomer($user)
            ->state(['created_at' => now()->subDays(5)])->create();

        $newer = SupportTicket::factory()->forUser($user)->open()
            ->state(['created_at' => now()->subDay()])->create();
        SupportTicketMessage::factory()->forTicket($newer)->fromCustomer($user)
            ->state(['created_at' => now()->subDay()])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$newer->id, $older->id]);
    });

    it('excludes other customers\' tickets', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();

        SupportTicket::factory()->forUser($user)->open()->create();

        $stranger = User::factory()->customer()->create();
        SupportTicket::factory()->forUser($stranger)->open()->create();
        SupportTicket::factory()->forUser($stranger)->open()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('filters by status=open', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();

        SupportTicket::factory()->forUser($user)->open()->create();
        SupportTicket::factory()->forUser($user)->cancelled()->create();
        SupportTicket::factory()->forUser($user)->closed()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets?status=open');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect($response->json('data.0.status'))->toBe(SupportTicketStatus::Open->value);
    });

    it('filters by category=order_issue', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();

        SupportTicket::factory()->forUser($user)->open()
            ->withCategory(SupportTicketCategory::OrderIssue)->create();
        SupportTicket::factory()->forUser($user)->open()
            ->withCategory(SupportTicketCategory::PaymentIssue)->create();
        SupportTicket::factory()->forUser($user)->open()
            ->withCategory(SupportTicketCategory::Feedback)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets?category=order_issue');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect($response->json('data.0.category'))->toBe(SupportTicketCategory::OrderIssue->value);
    });

    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $response = $this->getJson('/api/v1/support-tickets');

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 422 when status filter is not a valid enum', function (): void {
        ['token' => $token] = makeSupportListShowCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets?status=nonsense');

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/support-tickets/{id} (show)
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/support-tickets/{id}', function (): void {
    it('returns 200 with detail envelope including thread, attachments, status history and related summaries', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();

        // Related order
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = Order::factory()->forUser($user)->forAddress($address)
            ->state(['currency' => 'AED'])->create();

        // Related machine
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($user)->forProduct($product)
            ->state(['address_id' => $address->id])->create();

        $assignee = User::factory()->admin()->create(['name' => 'Layla AlMansouri']);

        $ticket = SupportTicket::factory()->forUser($user)
            ->state([
                'subject' => 'Detail-view probe',
                'status' => SupportTicketStatus::Assigned->value,
                'assigned_to_user_id' => $assignee->id,
                'related_order_id' => $order->id,
                'related_machine_id' => $machine->id,
                'category' => SupportTicketCategory::OrderIssue->value,
            ])->create();

        $firstMsg = SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($user)->create();
        $file = File::factory()->for($user)->forType(FileType::SupportAttachment)->create();
        SupportTicketAttachment::factory()
            ->forTicket($ticket)
            ->forMessage($firstMsg)
            ->forFile($file)
            ->create();

        // Status history rows
        SupportTicketStatusHistory::query()->create([
            'support_ticket_id' => $ticket->id,
            'from_status' => null,
            'to_status' => SupportTicketStatus::Open->value,
            'actor_type' => 'customer',
            'actor_user_id' => $user->id,
            'note' => null,
        ]);
        SupportTicketStatusHistory::query()->create([
            'support_ticket_id' => $ticket->id,
            'from_status' => SupportTicketStatus::Open->value,
            'to_status' => SupportTicketStatus::Assigned->value,
            'actor_type' => 'admin',
            'actor_user_id' => $assignee->id,
            'note' => 'Picked up by support tier 1',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/support-tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.status', SupportTicketStatus::Assigned->value)
            ->assertJsonStructure([
                'data' => [
                    'id', 'public_id', 'subject', 'category', 'priority', 'status',
                    'assigned_to_first_name', 'opened_at',
                    'messages', 'status_history', 'related_order', 'related_machine',
                ],
            ]);

        expect($response->json('data.status_history'))->toHaveCount(2);
        expect($response->json('data.messages'))->toHaveCount(1);
        expect($response->json('data.related_order.id'))->toBe($order->id);
        expect($response->json('data.related_machine.id'))->toBe($machine->id);
    });

    it('anonymises the assignee to first_name only — no last_name, no email', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();
        $assignee = User::factory()->admin()->create([
            'name' => 'Layla AlMansouri',
            'email' => 'layla@pwt.test',
        ]);

        $ticket = SupportTicket::factory()->forUser($user)
            ->state([
                'status' => SupportTicketStatus::Assigned->value,
                'assigned_to_user_id' => $assignee->id,
            ])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/support-tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('data.assigned_to_first_name', 'Layla');

        $body = $response->json('data');
        expect($body)->not->toHaveKey('assigned_to_user_id');
        // The assignee email must not surface anywhere in the detail body.
        expect(json_encode($body))->not->toContain('layla@pwt.test');
    });

    it('NEVER returns internal-note messages on the customer surface', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();
        $admin = User::factory()->admin()->create();

        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $customerMsg = SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($user)
            ->state(['body' => 'Visible to the customer.'])->create();

        $internalMsg = SupportTicketMessage::factory()->forTicket($ticket)->internalNote($admin)
            ->state(['body' => 'TOP-SECRET-INTERNAL-NOTE-MARKER'])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/support-tickets/{$ticket->id}");

        $response->assertOk();
        $bodies = collect($response->json('data.messages'))->pluck('body')->all();

        expect($bodies)->toContain('Visible to the customer.');
        expect($bodies)->not->toContain('TOP-SECRET-INTERNAL-NOTE-MARKER');
        expect(json_encode($response->json('data')))->not->toContain('TOP-SECRET-INTERNAL-NOTE-MARKER');
    });

    it('returns 403 ERR_FORBIDDEN when viewing another customer\'s ticket (not 404)', function (): void {
        ['token' => $token] = makeSupportListShowCustomer();

        $stranger = User::factory()->customer()->create();
        $strangerTicket = SupportTicket::factory()->forUser($stranger)->open()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/support-tickets/{$strangerTicket->id}");

        $response->assertStatus(403)->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        ['token' => $token] = makeSupportListShowCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets/9999999');

        $response->assertStatus(404)->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        ['user' => $user] = makeSupportListShowCustomer();
        $ticket = SupportTicket::factory()->forUser($user)->open()->create();

        $response = $this->getJson("/api/v1/support-tickets/{$ticket->id}");

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 404 for a non-numeric id (route constraint rejects the binding)', function (): void {
        ['token' => $token] = makeSupportListShowCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/support-tickets/abc');

        $response->assertStatus(404);
    });

    it('returns the Arabic success message under Accept-Language: ar; raw user body is unchanged', function (): void {
        ['user' => $user, 'token' => $token] = makeSupportListShowCustomer();

        $ticket = SupportTicket::factory()->forUser($user)->open()
            ->state(['subject' => 'هذا موضوع باللغة العربية'])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($user)
            ->state(['body' => 'محتوى نصي بلغة العربية لم يُترجَم'])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/support-tickets/{$ticket->id}");

        $response->assertOk();
        // The Arabic translation of the success message should be present.
        expect((string) $response->json('message'))->toContain('تذكرة الدعم');
        // User-supplied subject + body are NOT translated — they pass through verbatim.
        expect($response->json('data.subject'))->toBe('هذا موضوع باللغة العربية');
        expect($response->json('data.messages.0.body'))->toBe('محتوى نصي بلغة العربية لم يُترجَم');
    });
});
