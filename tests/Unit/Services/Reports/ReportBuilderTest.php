<?php

declare(strict_types=1);

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\OrderStatus;
use App\Enums\ReportType;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Reports\ReportBuilder;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/*
|--------------------------------------------------------------------------
| ReportBuilder — unit tests
|--------------------------------------------------------------------------
|
| Aggregation math + shape contracts on a small fixture. The full
| MySQL-backed pipeline runs through these tests so SUM / AVG /
| TIMESTAMPDIFF behaviour is asserted end-to-end.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
    $this->builder = app(ReportBuilder::class);
});

/**
 * @return array{0: User, 1: Address}
 */
function reportBuilderCustomer(?Country $country = null): array
{
    $country = $country ?? Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($country)->create([
        'is_default' => true,
    ]);

    return [$user, $address];
}

describe('ReportBuilder::sales()', function (): void {
    it('aggregates orders_count, subtotal_gross, tax_total, net_revenue, and AOV', function (): void {
        [$customer, $address] = reportBuilderCustomer();

        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'subtotal_amount' => '1000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '50.00',
            'shipping_amount' => '0.00',
            'total_amount' => '1050.00',
            'currency' => 'AED',
        ])->create();

        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'subtotal_amount' => '500.00',
            'discount_amount' => '50.00',
            'tax_amount' => '22.50',
            'shipping_amount' => '0.00',
            'total_amount' => '472.50',
            'currency' => 'AED',
        ])->create();

        // Cancelled — excluded from summary.
        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Cancelled->value,
            'subtotal_amount' => '9999.00',
            'total_amount' => '9999.00',
            'cancelled_at' => now(),
            'cancellation_reason' => 'test',
        ])->create();

        $report = $this->builder->sales([]);

        expect($report['summary']['orders_count'])->toBe(2);
        expect((float) $report['summary']['subtotal_gross'])->toBe(1500.00);
        expect((float) $report['summary']['tax_total'])->toBe(72.50);
        expect((float) $report['summary']['net_revenue'])->toBe(1522.50);
        expect((float) $report['summary']['average_order_value'])->toBe(round(1522.50 / 2, 2));
        expect($report['summary']['currency'])->toBe('AED');
    });

    it('returns zero summary when no orders match', function (): void {
        $report = $this->builder->sales([]);

        expect($report['summary']['orders_count'])->toBe(0);
        expect((float) $report['summary']['net_revenue'])->toBe(0.0);
        expect((float) $report['summary']['average_order_value'])->toBe(0.0);
    });

    it('returns the standard {filters, summary, buckets, rows} shape', function (): void {
        $report = $this->builder->sales([]);

        expect($report)->toHaveKeys(['filters', 'summary', 'buckets', 'rows']);
        expect($report['filters'])->toBeArray()->toHaveKey('date_from')->toHaveKey('date_to');
        expect($report['buckets'])->toBeArray();
        expect($report['rows'])->toBeInstanceOf(LengthAwarePaginator::class);
    });

    it('includes cancelled orders in rows but excludes them from buckets', function (): void {
        [$customer, $address] = reportBuilderCustomer();
        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'subtotal_amount' => '100.00',
            'total_amount' => '105.00',
        ])->create();
        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Cancelled->value,
            'total_amount' => '500.00',
            'cancelled_at' => now(),
            'cancellation_reason' => 'test',
        ])->create();

        $report = $this->builder->sales([]);

        expect($report['rows']->total())->toBe(2);
        $bucketRevenue = array_sum(array_map(
            static fn (array $b): float => (float) $b['net_revenue'],
            $report['buckets'],
        ));
        expect($bucketRevenue)->toBe(105.0);
    });

    it('filters by product_id via the order_items join', function (): void {
        [$customer, $address] = reportBuilderCustomer();
        $needle = Product::factory()->create();
        $noise = Product::factory()->create();

        $orderA = Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'total_amount' => '500.00',
        ])->create();
        OrderItem::factory()->forOrder($orderA)->forProduct($needle)->create();

        $orderB = Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'total_amount' => '999.00',
        ])->create();
        OrderItem::factory()->forOrder($orderB)->forProduct($noise)->create();

        $report = $this->builder->sales(['product_id' => $needle->id]);

        expect($report['summary']['orders_count'])->toBe(1);
    });
});

describe('ReportBuilder::orders()', function (): void {
    it('returns by_status with every OrderStatus key', function (): void {
        [$customer, $address] = reportBuilderCustomer();
        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Pending->value,
            'total_amount' => '100.00',
        ])->create();
        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'total_amount' => '200.00',
        ])->create();

        $report = $this->builder->orders([]);

        expect($report['summary']['orders_count'])->toBe(2);
        expect((float) $report['summary']['total_gross'])->toBe(300.0);
        $byStatus = $report['summary']['by_status'];
        foreach (OrderStatus::values() as $value) {
            expect($byStatus)->toHaveKey($value);
        }
        expect($byStatus['pending'])->toBe(1);
        expect($byStatus['completed'])->toBe(1);
    });

    it('returns the standard {filters, summary, buckets, rows} shape', function (): void {
        $report = $this->builder->orders([]);

        expect($report)->toHaveKeys(['filters', 'summary', 'buckets', 'rows']);
        expect($report['summary'])->toHaveKeys(['orders_count', 'total_gross', 'currency', 'by_status']);
    });
});

describe('ReportBuilder::maintenance()', function (): void {
    it('exposes requests_count, by_status, by_priority, average_resolution_hours', function (): void {
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
            'address_id' => $address->id,
            'country_code' => 'ae',
        ])->create();

        // Completed request — 48h resolution.
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->state([
            'status' => MaintenanceStatus::Completed->value,
            'priority' => MaintenancePriority::High->value,
            'created_at' => now()->subDays(2),
            'closed_at' => now(),
            'resolution_notes' => 'done',
        ])->create();

        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->state([
            'status' => MaintenanceStatus::New->value,
            'priority' => MaintenancePriority::Medium->value,
        ])->create();

        $report = $this->builder->maintenance([]);

        expect($report['summary']['requests_count'])->toBe(2);
        expect($report['summary']['by_status'])->toHaveKeys(['new', 'assigned', 'in_progress', 'completed', 'cancelled']);
        expect($report['summary']['by_status']['completed'])->toBe(1);
        expect($report['summary']['by_status']['new'])->toBe(1);
        expect($report['summary']['by_priority'])->toHaveKeys(['low', 'medium', 'high', 'urgent']);
        expect($report['summary']['average_resolution_hours'])->not->toBeNull();
        expect($report['summary']['average_resolution_hours'])->toBeGreaterThan(0.0);
    });

    it('returns null average_resolution_hours when no completed rows exist', function (): void {
        $report = $this->builder->maintenance([]);
        expect($report['summary']['average_resolution_hours'])->toBeNull();
    });
});

describe('ReportBuilder::customers()', function (): void {
    it('exposes customers_count, active_count, with_orders_count, with_machines_count', function (): void {
        $active = User::factory()->customer()->create(['is_active' => true]);
        $activeAddress = Address::factory()->forUser($active)->forCountry($this->uae)->create(['is_default' => true]);
        Order::factory()->forUser($active)->forAddress($activeAddress)->state([
            'status' => OrderStatus::Completed->value,
            'total_amount' => '500.00',
        ])->create();

        $inactive = User::factory()->customer()->create(['is_active' => false]);
        Address::factory()->forUser($inactive)->forCountry($this->uae)->create();

        $product = Product::factory()->create();
        Machine::factory()->forUser($active)->forProduct($product)->state([
            'address_id' => $activeAddress->id,
        ])->create();

        $report = $this->builder->customers([]);

        expect($report['summary']['customers_count'])->toBeGreaterThanOrEqual(2);
        expect($report['summary']['active_count'])->toBeGreaterThanOrEqual(1);
        expect($report['summary']['with_orders_count'])->toBe(1);
        expect($report['summary']['with_machines_count'])->toBe(1);
    });

    it('returns the {filters, summary, buckets, rows} shape with paginator rows', function (): void {
        $report = $this->builder->customers([]);

        expect($report)->toHaveKeys(['filters', 'summary', 'buckets', 'rows']);
        expect($report['rows'])->toBeInstanceOf(LengthAwarePaginator::class);
    });
});

describe('ReportBuilder::exportData()', function (): void {
    it('returns a header, generator, total, and truncated flag', function (): void {
        [$customer, $address] = reportBuilderCustomer();
        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'total_amount' => '1050.00',
        ])->create();

        $data = $this->builder->exportData(ReportType::Sales, []);

        expect($data)->toHaveKeys(['header', 'rows', 'total', 'truncated', 'type', 'date_from', 'date_to']);
        expect($data['type'])->toBe('sales');
        expect($data['header'])->toBeArray()->not->toBeEmpty();
        expect($data['rows'])->toBeInstanceOf(Generator::class);
        expect($data['truncated'])->toBeFalse();
    });

    it('flags truncated=false when total <= 10000', function (): void {
        $data = $this->builder->exportData(ReportType::Sales, []);
        expect($data['truncated'])->toBeFalse();
        expect($data['total'])->toBeLessThanOrEqual(ReportBuilder::EXPORT_ROW_CAP);
    });
});
