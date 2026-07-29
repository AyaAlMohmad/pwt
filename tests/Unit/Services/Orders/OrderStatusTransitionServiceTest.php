<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Exceptions\Orders\InvalidStatusTransitionException;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatusTransitionService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| OrderStatusTransitionService — full state-machine matrix
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/orders.md § 2.3 +
| OrderStatus::transitionsAllowed(). Every allowed transition must
| succeed AND write a row to order_status_history. Every disallowed
| transition must throw InvalidStatusTransitionException with code
| ERR_INVALID_STATUS_TRANSITION (409).
|
| CLAUDE.md § 10 explicitly requires this matrix test for the orders
| module.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->service = new OrderStatusTransitionService;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function transitionOrder(OrderStatus $start): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();

    // Hydrate the status-specific timestamp columns so the order looks
    // realistic for the chosen starting state.
    $state = ['status' => $start->value, 'currency' => 'AED'];
    if ($start === OrderStatus::Confirmed) {
        $state['confirmed_at'] = now()->subHour();
    }
    if ($start === OrderStatus::Scheduled) {
        $state['confirmed_at'] = now()->subDay();
        $state['scheduled_for_at'] = now()->addDay();
    }
    if ($start === OrderStatus::OutForDelivery) {
        $state['confirmed_at'] = now()->subDays(2);
        $state['scheduled_for_at'] = now()->subDay();
    }
    if ($start === OrderStatus::Installed) {
        $state['confirmed_at'] = now()->subDays(3);
        $state['scheduled_for_at'] = now()->subDay();
        $state['installed_at'] = now()->subHour();
    }
    if ($start === OrderStatus::Completed) {
        $state['confirmed_at'] = now()->subDays(10);
        $state['scheduled_for_at'] = now()->subDays(5);
        $state['installed_at'] = now()->subDays(2);
        $state['completed_at'] = now()->subDay();
    }
    if ($start === OrderStatus::Cancelled) {
        $state['cancelled_at'] = now()->subHour();
        $state['cancellation_reason'] = 'historical';
    }

    return Order::factory()->forUser($user)->forAddress($address)->state($state)->create();
}

// Full transition matrix from OrderStatus::transitionsAllowed().
// Each entry: [from, to, allowed]. Walks every (from, to) pair
// explicitly so any future map change is caught by these tests.
$matrix = [
    // pending → ...
    [OrderStatus::Pending, OrderStatus::Confirmed, true],
    [OrderStatus::Pending, OrderStatus::Cancelled, true],
    [OrderStatus::Pending, OrderStatus::Scheduled, false],
    [OrderStatus::Pending, OrderStatus::OutForDelivery, false],
    [OrderStatus::Pending, OrderStatus::Installed, false],
    [OrderStatus::Pending, OrderStatus::Completed, false],
    // confirmed → ...
    [OrderStatus::Confirmed, OrderStatus::Scheduled, true],
    [OrderStatus::Confirmed, OrderStatus::Cancelled, true],
    [OrderStatus::Confirmed, OrderStatus::Pending, false],
    [OrderStatus::Confirmed, OrderStatus::OutForDelivery, false],
    [OrderStatus::Confirmed, OrderStatus::Installed, false],
    [OrderStatus::Confirmed, OrderStatus::Completed, false],
    // scheduled → ...
    [OrderStatus::Scheduled, OrderStatus::OutForDelivery, true],
    [OrderStatus::Scheduled, OrderStatus::Cancelled, true],
    [OrderStatus::Scheduled, OrderStatus::Confirmed, false],
    [OrderStatus::Scheduled, OrderStatus::Pending, false],
    [OrderStatus::Scheduled, OrderStatus::Installed, false],
    [OrderStatus::Scheduled, OrderStatus::Completed, false],
    // out_for_delivery → ...
    [OrderStatus::OutForDelivery, OrderStatus::Installed, true],
    [OrderStatus::OutForDelivery, OrderStatus::Cancelled, true],
    [OrderStatus::OutForDelivery, OrderStatus::Pending, false],
    [OrderStatus::OutForDelivery, OrderStatus::Confirmed, false],
    [OrderStatus::OutForDelivery, OrderStatus::Scheduled, false],
    [OrderStatus::OutForDelivery, OrderStatus::Completed, false],
    // installed → ...
    [OrderStatus::Installed, OrderStatus::Completed, true],
    [OrderStatus::Installed, OrderStatus::Cancelled, true],
    [OrderStatus::Installed, OrderStatus::Pending, false],
    [OrderStatus::Installed, OrderStatus::Confirmed, false],
    [OrderStatus::Installed, OrderStatus::Scheduled, false],
    [OrderStatus::Installed, OrderStatus::OutForDelivery, false],
    // completed → terminal, all denied
    [OrderStatus::Completed, OrderStatus::Pending, false],
    [OrderStatus::Completed, OrderStatus::Confirmed, false],
    [OrderStatus::Completed, OrderStatus::Scheduled, false],
    [OrderStatus::Completed, OrderStatus::OutForDelivery, false],
    [OrderStatus::Completed, OrderStatus::Installed, false],
    [OrderStatus::Completed, OrderStatus::Cancelled, false],
    // cancelled → terminal, all denied
    [OrderStatus::Cancelled, OrderStatus::Pending, false],
    [OrderStatus::Cancelled, OrderStatus::Confirmed, false],
    [OrderStatus::Cancelled, OrderStatus::Scheduled, false],
    [OrderStatus::Cancelled, OrderStatus::OutForDelivery, false],
    [OrderStatus::Cancelled, OrderStatus::Installed, false],
    [OrderStatus::Cancelled, OrderStatus::Completed, false],
];

describe('OrderStatusTransitionService — allowed transitions', function () use ($matrix): void {
    foreach (array_filter($matrix, fn ($row) => $row[2] === true) as $row) {
        [$from, $to] = $row;
        $label = $from->value.' → '.$to->value;

        it("allows $label and writes a status_history row with from/to/actor", function () use ($from, $to): void {
            $order = transitionOrder($from);

            $result = $this->service->transition(
                order: $order,
                target: $to,
                actorType: OrderActorType::System,
                actor: null,
                note: 'matrix test',
            );

            expect($result->status)->toBe($to);

            $this->assertDatabaseHas('order_status_history', [
                'order_id' => $order->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => OrderActorType::System->value,
                'note' => 'matrix test',
            ]);
        });
    }
});

describe('OrderStatusTransitionService — disallowed transitions', function () use ($matrix): void {
    foreach (array_filter($matrix, fn ($row) => $row[2] === false) as $row) {
        [$from, $to] = $row;
        $label = $from->value.' → '.$to->value;

        it("rejects $label with InvalidStatusTransitionException (ERR_INVALID_STATUS_TRANSITION)", function () use ($from, $to): void {
            $order = transitionOrder($from);

            $thrown = null;
            try {
                $this->service->transition(
                    order: $order,
                    target: $to,
                    actorType: OrderActorType::Admin,
                    actor: null,
                    note: null,
                );
            } catch (InvalidStatusTransitionException $e) {
                $thrown = $e;
            }

            expect($thrown)->not->toBeNull()
                ->and($thrown->status)->toBe(409)
                ->and($thrown->errorCode)->toBe('ERR_INVALID_STATUS_TRANSITION');

            // Order's status remains unchanged after a rejected transition.
            $order->refresh();
            expect($order->status)->toBe($from);
        });
    }
});

describe('OrderStatusTransitionService — side-effects', function (): void {
    it('sets confirmed_at on pending → confirmed', function (): void {
        $order = transitionOrder(OrderStatus::Pending);
        expect($order->confirmed_at)->toBeNull();

        $result = $this->service->transition(
            order: $order, target: OrderStatus::Confirmed,
            actorType: OrderActorType::System, actor: null, note: null,
        );

        expect($result->confirmed_at)->not->toBeNull();
    });

    it('sets installed_at on out_for_delivery → installed', function (): void {
        $order = transitionOrder(OrderStatus::OutForDelivery);

        $result = $this->service->transition(
            order: $order, target: OrderStatus::Installed,
            actorType: OrderActorType::Admin, actor: null, note: null,
        );

        expect($result->installed_at)->not->toBeNull();
    });

    it('sets completed_at on installed → completed', function (): void {
        $order = transitionOrder(OrderStatus::Installed);

        $result = $this->service->transition(
            order: $order, target: OrderStatus::Completed,
            actorType: OrderActorType::System, actor: null, note: null,
        );

        expect($result->completed_at)->not->toBeNull();
    });

    it('sets cancelled_at on any allowed → cancelled transition', function (): void {
        $order = transitionOrder(OrderStatus::Confirmed);

        $result = $this->service->transition(
            order: $order, target: OrderStatus::Cancelled,
            actorType: OrderActorType::Customer, actor: null, note: 'cancelled',
        );

        expect($result->cancelled_at)->not->toBeNull();
    });

    it('persists the actor_id when an actor User is supplied', function (): void {
        $order = transitionOrder(OrderStatus::Pending);
        $admin = User::factory()->admin()->create();

        $this->service->transition(
            order: $order, target: OrderStatus::Confirmed,
            actorType: OrderActorType::Admin, actor: $admin, note: null,
        );

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => 'confirmed',
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
        ]);
    });
});
