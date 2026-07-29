<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\DashboardService;
use App\Services\Admin\Dto\DashboardSnapshotDto;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| DashboardService — unit
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md
| § 4.4 / § 6.5 / § 9.7.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->service = app(DashboardService::class);
    Cache::flush();
    config(['dashboard.cache_ttl' => 0]);
});

function dsCustomerWithAddress(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create(['is_default' => true]);

    return [$user, $address];
}

describe('DashboardService::compute()', function (): void {
    it('returns a DashboardSnapshotDto with the documented shape', function (): void {
        $dto = $this->service->compute();

        expect($dto)->toBeInstanceOf(DashboardSnapshotDto::class)
            ->and($dto->orders)->toBeArray()
            ->and($dto->revenue)->toBeArray()
            ->and($dto->maintenance)->toBeArray()
            ->and($dto->customers)->toBeArray()
            ->and($dto->machines)->toBeArray();
    });

    it('orders block carries every OrderStatus key plus total_this_month', function (): void {
        $dto = $this->service->compute();

        foreach (OrderStatus::values() as $statusValue) {
            expect($dto->orders)->toHaveKey($statusValue);
        }
        expect($dto->orders)->toHaveKey('total_this_month');
    });

    it('counts per-status, totals this-month, excludes cancelled from revenue', function (): void {
        [$customer, $address] = dsCustomerWithAddress();

        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Pending->value, 'total_amount' => '100.00'])->create();
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Confirmed->value, 'total_amount' => '200.00', 'confirmed_at' => now()])->create();
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Cancelled->value, 'total_amount' => '500.00', 'cancelled_at' => now()])->create();

        $dto = $this->service->compute();

        expect($dto->orders[OrderStatus::Pending->value])->toBe(1)
            ->and($dto->orders[OrderStatus::Confirmed->value])->toBe(1)
            ->and($dto->orders[OrderStatus::Cancelled->value])->toBe(1)
            ->and($dto->orders['total_this_month'])->toBe(3)
            ->and($dto->revenue['this_month'])->toBe('300.00')
            ->and($dto->revenue['currency'])->toBe('AED');
    });

    it('returns 0.00 revenue when only cancelled orders exist', function (): void {
        [$customer, $address] = dsCustomerWithAddress();

        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Cancelled->value, 'total_amount' => '999.99', 'cancelled_at' => now()])->create();

        $dto = $this->service->compute();
        expect($dto->revenue['this_month'])->toBe('0.00');
    });

    it('counts pending (new/assigned/in_progress) maintenance requests only', function (): void {
        [$customer, $address] = dsCustomerWithAddress();
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id])->create();

        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::New->value])->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::Assigned->value])->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::InProgress->value])->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state([
                'status' => MaintenanceStatus::Completed->value,
                'resolution_notes' => 'done',
                'closed_at' => now(),
            ])->create();

        $dto = $this->service->compute();
        expect($dto->maintenance['pending_requests'])->toBe(3);
    });

    it('counts customer users only (excludes admins)', function (): void {
        User::factory()->customer()->create(['is_active' => true]);
        User::factory()->customer()->create(['is_active' => true]);
        User::factory()->customer()->inactive()->create();
        User::factory()->admin(UserRole::Operations->value)->create();
        User::factory()->admin(UserRole::SuperAdmin->value)->create();

        $dto = $this->service->compute();
        expect($dto->customers['total_active'])->toBe(2)
            ->and($dto->customers['new_this_month'])->toBe(3);
    });

    it('counts machines (total_active + due_maintenance_30d)', function (): void {
        [$customer, $address] = dsCustomerWithAddress();
        $product = Product::factory()->create();

        Machine::factory()->forUser($customer)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'is_active' => true,
                'next_maintenance_due_at' => now()->addDays(5)->toDateString(),
            ])->create();
        Machine::factory()->forUser($customer)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'is_active' => true,
                'next_maintenance_due_at' => now()->addDays(120)->toDateString(),
            ])->create();
        Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id, 'is_active' => false])->create();

        $dto = $this->service->compute();
        expect($dto->machines['total_active'])->toBe(2)
            ->and($dto->machines['due_maintenance_30d'])->toBe(1);
    });

    it('returns zeroed counts on an empty database', function (): void {
        $dto = $this->service->compute();

        foreach (OrderStatus::values() as $value) {
            expect($dto->orders[$value])->toBe(0);
        }
        expect($dto->orders['total_this_month'])->toBe(0)
            ->and($dto->revenue['this_month'])->toBe('0.00')
            ->and($dto->maintenance['pending_requests'])->toBe(0)
            ->and($dto->maintenance['due_this_month'])->toBe(0)
            ->and($dto->machines['total_active'])->toBe(0)
            ->and($dto->machines['due_maintenance_30d'])->toBe(0);
    });
});

describe('DashboardService::snapshot() — caching', function (): void {
    it('bypasses the cache when TTL=0', function (): void {
        config(['dashboard.cache_ttl' => 0]);

        $first = $this->service->snapshot();
        // Mutate DB; the snapshot MUST recompute because we never hit cache.
        [$customer, $address] = dsCustomerWithAddress();
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Pending->value, 'total_amount' => '10.00'])->create();
        $second = $this->service->snapshot();

        expect($first->orders[OrderStatus::Pending->value])->toBe(0)
            ->and($second->orders[OrderStatus::Pending->value])->toBe(1);
    });

    it('returns the cached snapshot on the second call within TTL', function (): void {
        config(['dashboard.cache_ttl' => 60]);
        Cache::flush();

        $first = $this->service->snapshot();

        // Mutate the DB AFTER the snapshot is primed. A cached call MUST
        // return the original numbers; only an expired/missing cache key
        // would re-aggregate and surface the new row.
        [$customer, $address] = dsCustomerWithAddress();
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Pending->value, 'total_amount' => '10.00'])->create();

        $second = $this->service->snapshot();
        expect($second->orders[OrderStatus::Pending->value])->toBe($first->orders[OrderStatus::Pending->value]);
    });

    it('isCached() reports true once the cache is primed', function (): void {
        config(['dashboard.cache_ttl' => 60]);
        Cache::flush();

        expect($this->service->isCached())->toBeFalse();
        $this->service->snapshot();
        expect($this->service->isCached())->toBeTrue();
    });

    it('isCached() always returns false when TTL=0', function (): void {
        config(['dashboard.cache_ttl' => 0]);
        $this->service->snapshot();
        expect($this->service->isCached())->toBeFalse();
    });
});
