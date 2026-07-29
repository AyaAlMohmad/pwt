<?php

declare(strict_types=1);

use App\Enums\MachineSource;
use App\Enums\ProductTerm;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Maintenance\MachineCreationFromOrderService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| MachineCreationFromOrderService — unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/machines-maintenance.md § 9.7.
| Listener-driven service: when an order transitions to `installed`, it
| creates one Machine per (item × quantity) unit. Idempotent on order_id.
| Each created row carries source='created' and a sentinel
| `PENDING-{order_public_id}-{line}-{qty}` serial.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->service = new MachineCreationFromOrderService;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * Helper: create a fully-formed installed order with $items lines.
 *
 * @param  array<int, array{product?:Product, quantity?:int}>  $items
 * @return array{user:User, address:Address, order:Order, items:list<OrderItem>}
 */
function machineCreationOrder(array $items): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'label' => 'Test Location',
    ]);
    $order = Order::factory()->forUser($user)->forAddress($address)->installed()->state([
        'currency' => 'AED',
    ])->create();

    $createdItems = [];
    foreach ($items as $itemSpec) {
        $product = $itemSpec['product'] ?? Product::factory()->create();
        $quantity = (int) ($itemSpec['quantity'] ?? 1);
        $createdItems[] = OrderItem::factory()
            ->forOrder($order)
            ->forProduct($product)
            ->state([
                'term' => ProductTerm::Buy->value,
                'quantity' => $quantity,
            ])
            ->create();
    }

    return [
        'user' => $user,
        'address' => $address,
        'order' => $order->refresh(),
        'items' => $createdItems,
    ];
}

describe('MachineCreationFromOrderService::createFromOrder()', function (): void {
    it('creates a single Machine when the order has one item with quantity 1', function (): void {
        $product = Product::factory()->create(['is_machine_eligible' => true]);
        ['order' => $order, 'user' => $user] = machineCreationOrder([
            ['product' => $product, 'quantity' => 1],
        ]);

        $created = $this->service->createFromOrder($order);

        expect($created)->toHaveCount(1);
        expect($created[0]->user_id)->toBe($user->id);
        expect($created[0]->product_id)->toBe($product->id);
        expect($created[0]->source)->toBe(MachineSource::Created);
        expect($created[0]->serial_number)->toStartWith('PENDING-');
    });

    it('creates one Machine per quantity unit (3 total for quantities 2 + 1)', function (): void {
        $productA = Product::factory()->create(['is_machine_eligible' => true]);
        $productB = Product::factory()->create(['is_machine_eligible' => true]);

        ['order' => $order] = machineCreationOrder([
            ['product' => $productA, 'quantity' => 2],
            ['product' => $productB, 'quantity' => 1],
        ]);

        $created = $this->service->createFromOrder($order);

        expect($created)->toHaveCount(3);

        $countA = collect($created)->filter(fn (Machine $m) => $m->product_id === $productA->id)->count();
        $countB = collect($created)->filter(fn (Machine $m) => $m->product_id === $productB->id)->count();

        expect($countA)->toBe(2);
        expect($countB)->toBe(1);
    });

    it('marks every created machine with source=created and a sentinel PENDING- serial', function (): void {
        $product = Product::factory()->create(['is_machine_eligible' => true]);
        ['order' => $order] = machineCreationOrder([
            ['product' => $product, 'quantity' => 2],
        ]);

        $created = $this->service->createFromOrder($order);

        foreach ($created as $machine) {
            expect($machine->source)->toBe(MachineSource::Created);
            expect($machine->serial_number)->toStartWith('PENDING-');
            expect($machine->order_id)->toBe($order->id);
        }
    });

    it('links each Machine to the order\'s user_id and address_id', function (): void {
        $product = Product::factory()->create(['is_machine_eligible' => true]);
        ['order' => $order, 'user' => $user, 'address' => $address] = machineCreationOrder([
            ['product' => $product, 'quantity' => 1],
        ]);

        $created = $this->service->createFromOrder($order);

        expect($created[0]->user_id)->toBe($user->id);
        expect($created[0]->address_id)->toBe($address->id);
    });

    it('is idempotent on order_id — calling twice does not duplicate rows', function (): void {
        $product = Product::factory()->create(['is_machine_eligible' => true]);
        ['order' => $order] = machineCreationOrder([
            ['product' => $product, 'quantity' => 2],
        ]);

        $this->service->createFromOrder($order);
        $second = $this->service->createFromOrder($order);

        expect($second)->toHaveCount(2);
        expect(Machine::query()->where('order_id', $order->id)->count())->toBe(2);
    });

    it('skips line items whose product is not machine-eligible', function (): void {
        $eligible = Product::factory()->create(['is_machine_eligible' => true]);
        $accessory = Product::factory()->create(['is_machine_eligible' => false]);

        ['order' => $order] = machineCreationOrder([
            ['product' => $eligible, 'quantity' => 1],
            ['product' => $accessory, 'quantity' => 3],
        ]);

        $created = $this->service->createFromOrder($order);

        expect($created)->toHaveCount(1);
        expect($created[0]->product_id)->toBe($eligible->id);
    });
});
