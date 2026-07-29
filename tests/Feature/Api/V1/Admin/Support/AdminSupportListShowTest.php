<?php

declare(strict_types=1);

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/support-tickets   (list)
| GET /api/v1/admin/support-tickets/{id}   (show)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-support.md § 1 / § 3.1 / § 3.2 / § 4.1 /
| § 4.2. Permission gate: `support.view` (super_admin + support + operations).
| Admin show MUST surface internal notes (admins see everything).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function adminSupportBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function adminSupportTicket(?User $customer = null, array $overrides = []): SupportTicket
{
    $customer = $customer ?? User::factory()->customer()->create();

    return SupportTicket::factory()->forUser($customer)->state($overrides)->create();
}

/*
|--------------------------------------------------------------------------
| GET list
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/support-tickets — list', function (): void {
    it('returns 200 paginated list for super_admin', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::SuperAdmin->value);
        adminSupportTicket();
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    '*' => [
                        'id', 'public_id', 'subject', 'category', 'status', 'priority',
                        'customer' => ['id', 'name', 'email', 'phone'],
                        'assigned_to',
                        'related_order_id', 'related_machine_id',
                        'opened_at', 'first_response_at', 'last_message_at',
                        'closed_at', 'cancelled_at',
                        'message_count', 'internal_note_count', 'is_trashed', 'created_at',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total']],
            ])
            ->assertJsonPath('meta.pagination.total', 2);
    });

    it('returns 200 for support role', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::Support->value);
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('returns 200 for operations role (read-only)', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::Operations->value);
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('returns 403 for sales role', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::Sales->value);
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets');

        $response->assertStatus(403)->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for a customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;
        adminSupportTicket();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/support-tickets');

        $response->assertStatus(403);
    });

    it('returns 401 without a Bearer token', function (): void {
        $response = $this->getJson('/api/v1/admin/support-tickets');

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('filters by status[] (multi-value)', function (): void {
        [, $bearer] = adminSupportBearer();
        adminSupportTicket(null, ['status' => SupportTicketStatus::Open->value]);
        adminSupportTicket(null, ['status' => SupportTicketStatus::Open->value]);
        adminSupportTicket(null, ['status' => SupportTicketStatus::Cancelled->value, 'cancelled_at' => now()]);
        adminSupportTicket(null, ['status' => SupportTicketStatus::Closed->value, 'closed_at' => now()]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?status[]=open&status[]=cancelled');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 3);
    });

    it('filters by single status string (coerced to array)', function (): void {
        [, $bearer] = adminSupportBearer();
        adminSupportTicket(null, ['status' => SupportTicketStatus::Open->value]);
        adminSupportTicket(null, ['status' => SupportTicketStatus::Closed->value, 'closed_at' => now()]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?status=open');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('filters by priority', function (): void {
        [, $bearer] = adminSupportBearer();
        adminSupportTicket(null, ['priority' => SupportTicketPriority::Urgent->value]);
        adminSupportTicket(null, ['priority' => SupportTicketPriority::Medium->value]);
        adminSupportTicket(null, ['priority' => SupportTicketPriority::Low->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?priority=urgent');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect($response->json('data.0.priority'))->toBe('urgent');
    });

    it('filters by category', function (): void {
        [, $bearer] = adminSupportBearer();
        adminSupportTicket(null, ['category' => SupportTicketCategory::OrderIssue->value]);
        adminSupportTicket(null, ['category' => SupportTicketCategory::PaymentIssue->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?category=order_issue');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect($response->json('data.0.category'))->toBe('order_issue');
    });

    it('filters by search across subject + public_id + customer name', function (): void {
        [, $bearer] = adminSupportBearer();
        $target = User::factory()->customer()->state(['name' => 'Layla AlMansouri'])->create();
        adminSupportTicket($target, ['subject' => 'order #1057 installation question']);
        adminSupportTicket(null, ['subject' => 'completely unrelated subject']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?search=Layla');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('filters by assigned_to_user_id (specific admin)', function (): void {
        [, $bearer] = adminSupportBearer();
        $tier1 = User::factory()->admin(UserRole::Support->value)->create();
        adminSupportTicket(null, [
            'status' => SupportTicketStatus::Assigned->value,
            'assigned_to_user_id' => $tier1->id,
        ]);
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets?assigned_to_user_id={$tier1->id}");

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('filters by assigned_to_user_id=0 (unassigned)', function (): void {
        [, $bearer] = adminSupportBearer();
        $tier1 = User::factory()->admin(UserRole::Support->value)->create();
        adminSupportTicket(null, [
            'status' => SupportTicketStatus::Assigned->value,
            'assigned_to_user_id' => $tier1->id,
        ]);
        adminSupportTicket(); // unassigned, open
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?assigned_to_user_id=0');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 2);
    });

    it('filters by customer_id', function (): void {
        [, $bearer] = adminSupportBearer();
        $target = User::factory()->customer()->create();
        adminSupportTicket($target);
        adminSupportTicket($target);
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets?customer_id={$target->id}");

        $response->assertOk()->assertJsonPath('meta.pagination.total', 2);
    });

    it('filters by date_from and date_to inclusive', function (): void {
        [, $bearer] = adminSupportBearer();
        adminSupportTicket(null, ['created_at' => now()->subDays(10)]);
        adminSupportTicket(null, ['created_at' => now()->subDays(5)]);
        adminSupportTicket(null, ['created_at' => now()->subDay()]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->subDays(2)->toDateString();
        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets?date_from={$from}&date_to={$to}");

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('rejects date_to before date_from with 422', function (): void {
        [, $bearer] = adminSupportBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?date_from=2026-05-10&date_to=2026-05-01');

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date_to']]);
    });

    it('rejects invalid status enum with 422', function (): void {
        [, $bearer] = adminSupportBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?status=garbage');

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status.0']]);
    });

    it('rejects include_trashed for non-super_admin (support role) with 403', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::Support->value);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?include_trashed=1');

        $response->assertStatus(403);
    });

    it('include_trashed=1 returns trashed rows for super_admin', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::SuperAdmin->value);
        $alive = adminSupportTicket();
        $trashed = adminSupportTicket();
        $trashed->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets?include_trashed=1');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toContain($alive->id, $trashed->id);
    });

    it('returns Arabic success message when Accept-Language: ar', function (): void {
        [, $bearer] = adminSupportBearer();
        adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/support-tickets');

        $response->assertOk();
        expect((string) $response->json('message'))->toContain('استرجاع');
    });
});

/*
|--------------------------------------------------------------------------
| GET show
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/support-tickets/{id} — show', function (): void {
    it('returns 200 with full thread INCLUDING internal notes for support role', function (): void {
        [$admin, $bearer] = adminSupportBearer(UserRole::Support->value);
        $customer = User::factory()->customer()->create();

        $ticket = adminSupportTicket($customer, [
            'status' => SupportTicketStatus::Assigned->value,
            'assigned_to_user_id' => $admin->id,
        ]);
        SupportTicketMessage::factory()->forTicket($ticket)->fromCustomer($customer)
            ->state(['body' => 'Visible customer message.'])->create();
        SupportTicketMessage::factory()->forTicket($ticket)->internalNote($admin)
            ->state(['body' => 'INTERNAL-NOTE-FOR-ADMIN-EYES'])->create();
        SupportTicketStatusHistory::query()->create([
            'support_ticket_id' => $ticket->id,
            'from_status' => null,
            'to_status' => SupportTicketStatus::Open->value,
            'actor_type' => 'customer',
            'actor_user_id' => $customer->id,
            'note' => null,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonStructure([
                'data' => [
                    'id', 'public_id', 'subject', 'category', 'priority', 'status',
                    'customer' => ['id', 'name', 'email', 'phone'],
                    'assigned_to' => ['id', 'name', 'role'],
                    'resolution_summary', 'cancellation_reason',
                    'messages', 'status_history', 'deleted_at', 'updated_at',
                ],
            ]);

        $bodies = collect($response->json('data.messages'))->pluck('body')->all();
        expect($bodies)->toContain('Visible customer message.')
            ->and($bodies)->toContain('INTERNAL-NOTE-FOR-ADMIN-EYES');

        $hasInternal = collect($response->json('data.messages'))->pluck('is_internal_note')->contains(true);
        expect($hasInternal)->toBeTrue();
    });

    it('returns 200 with full detail for operations (read-only)', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::Operations->value);
        $ticket = adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertOk()->assertJsonPath('data.id', $ticket->id);
    });

    it('returns 200 with full detail for super_admin', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::SuperAdmin->value);
        $ticket = adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertOk()->assertJsonPath('data.id', $ticket->id);
    });

    it('returns 403 for sales role', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::Sales->value);
        $ticket = adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertStatus(403);
    });

    it('returns 404 ERR_NOT_FOUND for a non-existent id', function (): void {
        [, $bearer] = adminSupportBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/support-tickets/9999999');

        $response->assertStatus(404)->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 without a Bearer token', function (): void {
        $ticket = adminSupportTicket();

        $response = $this->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('exposes related_order summary when present', function (): void {
        [, $bearer] = adminSupportBearer();
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $order = Order::factory()->forUser($customer)->forAddress($address)
            ->state(['currency' => 'AED'])->create();
        $ticket = adminSupportTicket($customer, [
            'related_order_id' => $order->id,
            'category' => SupportTicketCategory::OrderIssue->value,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('data.related_order.id', $order->id)
            ->assertJsonPath('data.related_order.currency', 'AED');
    });

    it('exposes related_machine summary with product when present', function (): void {
        [, $bearer] = adminSupportBearer();
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id])->create();
        $ticket = adminSupportTicket($customer, [
            'related_machine_id' => $machine->id,
            'category' => SupportTicketCategory::MaintenanceIssue->value,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('data.related_machine.id', $machine->id)
            ->assertJsonPath('data.related_machine.product.id', $product->id);
    });

    it('returns Arabic success message when Accept-Language: ar', function (): void {
        [, $bearer] = adminSupportBearer();
        $ticket = adminSupportTicket();

        $response = $this->withHeader('Authorization', $bearer)
            ->withHeader('Accept-Language', 'ar')
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertOk();
        expect((string) $response->json('message'))->toContain('استرجاع');
    });

    it('soft-deleted tickets remain viewable for audit (super_admin)', function (): void {
        [, $bearer] = adminSupportBearer(UserRole::SuperAdmin->value);
        $ticket = adminSupportTicket();
        $ticket->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/support-tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.is_trashed', true);
    });
});
