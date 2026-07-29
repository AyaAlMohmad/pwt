<?php

declare(strict_types=1);

use App\Enums\InstallationStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\Orders\InvalidOrderStateException;
use App\Exceptions\Orders\InvalidStatusTransitionException;
use App\Exceptions\Orders\PaymentNotCapturedException;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\AdminOrderService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| AdminOrderService unit tests
|--------------------------------------------------------------------------
|
| Covers every public method of the admin orchestrator.
| See docs/architecture/admin-orders.md § 6 / § 10.1.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->service = app(AdminOrderService::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function svcOrder(array $overrides = []): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();

    return Order::factory()->forUser($customer)->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

describe('AdminOrderService::list()', function (): void {
    it('returns all orders by default (no ownership scope)', function (): void {
        svcOrder();
        svcOrder();

        $paginator = $this->service->list([], 1, 50);
        expect($paginator->total())->toBe(2);
    });

    it('filters by status array', function (): void {
        svcOrder(['status' => OrderStatus::Pending->value]);
        svcOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $paginator = $this->service->list(['status' => ['pending']], 1, 50);
        expect($paginator->total())->toBe(1);
    });

    it('filters by date range', function (): void {
        svcOrder(['created_at' => now()->subDays(30)]);
        svcOrder(['created_at' => now()->subDay()]);

        $paginator = $this->service->list(
            [
                'date_from' => now()->subDays(7)->format('Y-m-d'),
                'date_to' => now()->format('Y-m-d'),
            ],
            1,
            50,
        );
        expect($paginator->total())->toBe(1);
    });

    it('filters by search term against customer email', function (): void {
        $matchCustomer = User::factory()->customer()->create(['email' => 'svc.match@example.ae']);
        $matchAddress = Address::factory()->forUser($matchCustomer)->forCountry($this->uae)->create();
        Order::factory()->forUser($matchCustomer)->forAddress($matchAddress)->create();
        svcOrder();

        expect($this->service->list(['search' => 'svc.match'], 1, 50)->total())->toBe(1);
    });

    it('filters by country code via the delivery_address join', function (): void {
        $qatar = Country::query()->where('code', 'qa')->firstOrFail();
        $qatarCustomer = User::factory()->customer()->create();
        $qatarAddress = Address::factory()->forUser($qatarCustomer)->forCountry($qatar)->create();
        Order::factory()->forUser($qatarCustomer)->forAddress($qatarAddress)->create();
        svcOrder();

        expect($this->service->list(['country' => 'qa'], 1, 50)->total())->toBe(1);
        expect($this->service->list(['country' => 'ae'], 1, 50)->total())->toBe(1);
    });

    it('excludes trashed by default and includes them when include_trashed=true', function (): void {
        $order = svcOrder();
        $order->delete();
        svcOrder();

        expect($this->service->list([], 1, 50)->total())->toBe(1);
        expect($this->service->list(['include_trashed' => true], 1, 50)->total())->toBe(2);
    });

    it('falls back to created_at desc on unknown sort columns', function (): void {
        svcOrder();
        svcOrder();

        $paginator = $this->service->list(['sort' => 'bogus_column'], 1, 50);
        expect($paginator->total())->toBe(2);
    });
});

describe('AdminOrderService::find()', function (): void {
    it('throws ModelNotFoundException on unknown id', function (): void {
        $this->service->find(9999999);
    })->throws(ModelNotFoundException::class);

    it('returns a soft-deleted order (admins can audit trashed)', function (): void {
        $order = svcOrder();
        $order->delete();

        $found = $this->service->find($order->id);
        expect($found->id)->toBe($order->id)
            ->and($found->trashed())->toBeTrue();
    });

    it('eager-loads items, status_history, payments, installation, addresses', function (): void {
        $order = svcOrder();
        OrderItem::factory()->forOrder($order)->create();
        OrderStatusHistory::factory()->forOrder($order)->create();

        $found = $this->service->find($order->id);
        expect($found->relationLoaded('items'))->toBeTrue();
        expect($found->relationLoaded('statusHistory'))->toBeTrue();
        expect($found->relationLoaded('payments'))->toBeTrue();
        expect($found->relationLoaded('installation'))->toBeTrue();
        expect($found->relationLoaded('deliveryAddress'))->toBeTrue();
    });
});

describe('AdminOrderService::transitionStatus()', function (): void {
    it('rejects status=scheduled with InvalidOrderStateException (ERR_USE_SCHEDULE_ENDPOINT)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $order = svcOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $this->service->transitionStatus($order, OrderStatus::Scheduled, $admin, null);
    })->throws(InvalidOrderStateException::class);

    it('drives the state machine for valid transitions and stamps admin actor', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $updated = $this->service->transitionStatus($order, OrderStatus::Confirmed, $admin, 'note text', '127.0.0.1');
        expect($updated->status)->toBe(OrderStatus::Confirmed);

        $row = OrderStatusHistory::query()->where('order_id', $order->id)->latest('id')->first();
        expect($row->actor_type)->toBe(OrderActorType::Admin);
        expect($row->actor_id)->toBe($admin->id);
        expect($row->note)->toBe('note text');

        $entry = Activity::query()
            ->where('log_name', 'admin.orders')
            ->where('event', 'admin.order.status_updated')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
        expect($entry->causer_id)->toBe($admin->id);
        expect($entry->properties['ip'])->toBe('127.0.0.1');
    });

    it('throws InvalidStatusTransitionException for an illegal transition', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder(['status' => OrderStatus::Pending->value]);

        $this->service->transitionStatus($order, OrderStatus::Installed, $admin, null);
    })->throws(InvalidStatusTransitionException::class);

    it('throws PaymentNotCapturedException for online orders without captured payment', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $this->service->transitionStatus($order, OrderStatus::Confirmed, $admin, null);
    })->throws(PaymentNotCapturedException::class);

    it('allows pending → confirmed when an online order has a captured payment', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Card->value,
        ]);

        Payment::factory()->forOrder($order)->create([
            'status' => PaymentStatus::Captured->value,
            'gateway' => 'payfort',
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ]);

        $updated = $this->service->transitionStatus($order, OrderStatus::Confirmed, $admin, null);
        expect($updated->status)->toBe(OrderStatus::Confirmed);
    });

    it('trims an over-long note down to the persisted cap', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $long = str_repeat('A', 2000);
        $this->service->transitionStatus($order, OrderStatus::Confirmed, $admin, $long);

        $row = OrderStatusHistory::query()->where('order_id', $order->id)->latest('id')->first();
        // `Str::limit($note, 1000, '…')` retains <=1000 ASCII chars and
        // appends the (multi-byte) ellipsis terminator. Byte length is
        // bounded by 1000 + strlen('…') = 1003.
        expect(strlen((string) $row->note))->toBeLessThanOrEqual(1003);
        expect(mb_strlen((string) $row->note))->toBeLessThanOrEqual(1001);
    });
});

describe('AdminOrderService::cancel()', function (): void {
    it('cancels pending → cancelled and records cancelled_by_user_id + reason', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder(['status' => OrderStatus::Pending->value]);

        $cancelled = $this->service->cancel($order, $admin, 'Admin cancellation reason', '10.0.0.5');

        expect($cancelled->status)->toBe(OrderStatus::Cancelled);
        expect($cancelled->cancelled_by_user_id)->toBe($admin->id);
        expect($cancelled->cancellation_reason)->toBe('Admin cancellation reason');

        $entry = Activity::query()
            ->where('event', 'admin.order.cancelled')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['reason'])->toBe('Admin cancellation reason')
            ->and($entry->properties['ip'])->toBe('10.0.0.5');
    });

    it('cancels from confirmed, scheduled, out_for_delivery (admin broader window)', function (): void {
        $admin = User::factory()->admin()->create();

        foreach (
            [
                OrderStatus::Confirmed->value,
                OrderStatus::Scheduled->value,
                OrderStatus::OutForDelivery->value,
            ] as $source
        ) {
            $order = svcOrder([
                'status' => $source,
                'confirmed_at' => now()->subDay(),
                'scheduled_for_at' => $source === OrderStatus::Scheduled->value ? now()->addDay() : null,
            ]);
            $cancelled = $this->service->cancel($order, $admin, "Cancel from $source");
            expect($cancelled->status)->toBe(OrderStatus::Cancelled);
        }
    });

    it('throws InvalidStatusTransitionException when cancelling an installed order', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Installed->value,
            'installed_at' => now()->subDay(),
        ]);

        $this->service->cancel($order, $admin, 'Should not cancel installed');
    })->throws(InvalidStatusTransitionException::class);

    it('throws InvalidStatusTransitionException on terminal orders (completed/cancelled)', function (): void {
        $admin = User::factory()->admin()->create();
        $completed = svcOrder([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now()->subDay(),
        ]);

        try {
            $this->service->cancel($completed, $admin, 'Bad cancel');
            test()->fail('Expected InvalidStatusTransitionException for completed source state.');
        } catch (InvalidStatusTransitionException) {
            // Expected — second branch validates same path on cancelled.
        }

        $cancelledOrder = svcOrder([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now()->subHour(),
        ]);
        $this->service->cancel($cancelledOrder, $admin, 'Bad cancel double');
    })->throws(InvalidStatusTransitionException::class);
});

describe('AdminOrderService::scheduleInstallation()', function (): void {
    it('schedules a confirmed order and upserts the installation row', function (): void {
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        $order = svcOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subHour(),
        ]);

        $scheduledFor = now()->addDays(2)->setTime(11, 30);

        $scheduled = $this->service->scheduleInstallation(
            order: $order,
            scheduledFor: $scheduledFor,
            technicianUserId: $technician->id,
            installationNotes: 'Bring spare filter cartridge',
            actor: $admin,
            ip: '192.168.1.1',
        );

        expect($scheduled->status)->toBe(OrderStatus::Scheduled);
        $this->assertDatabaseHas('order_installations', [
            'order_id' => $order->id,
            'technician_user_id' => $technician->id,
            'status' => InstallationStatus::Scheduled->value,
            'installation_notes' => 'Bring spare filter cartridge',
        ]);

        $entry = Activity::query()
            ->where('event', 'admin.order.installation_scheduled')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['technician_user_id'])->toBe($technician->id);
    });

    it('throws InvalidOrderStateException when source state is not confirmed', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder(['status' => OrderStatus::Pending->value]);

        $this->service->scheduleInstallation(
            order: $order,
            scheduledFor: now()->addDays(2),
            technicianUserId: null,
            installationNotes: null,
            actor: $admin,
        );
    })->throws(InvalidOrderStateException::class);
});

describe('AdminOrderService::addNote()', function (): void {
    it('writes a notes-only row with is_internal=true and unchanged status', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subHour(),
        ]);

        $row = $this->service->addNote($order, 'Internal note text', $admin, '127.0.0.1');

        expect($row->order_id)->toBe($order->id)
            ->and($row->from_status)->toBe(OrderStatus::Confirmed->value)
            ->and($row->to_status)->toBe(OrderStatus::Confirmed->value)
            ->and($row->actor_type)->toBe(OrderActorType::Admin)
            ->and($row->actor_id)->toBe($admin->id)
            ->and($row->is_internal)->toBeTrue();

        // Order itself untouched.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Confirmed->value,
        ]);

        $entry = Activity::query()
            ->where('event', 'admin.order.note_added')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['history_id'])->toBe($row->id)
            ->and($entry->properties['ip'])->toBe('127.0.0.1');
    });

    it('throws InvalidOrderStateException on a terminal (completed) order', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now()->subDay(),
        ]);

        $this->service->addNote($order, 'note', $admin);
    })->throws(InvalidOrderStateException::class);

    it('throws InvalidOrderStateException on a cancelled order', function (): void {
        $admin = User::factory()->admin()->create();
        $order = svcOrder([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now()->subHour(),
        ]);

        $this->service->addNote($order, 'note', $admin);
    })->throws(InvalidOrderStateException::class);
});
