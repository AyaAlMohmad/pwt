<?php

declare(strict_types=1);

use App\Enums\MachineSource;
use App\Enums\ProductTerm;
use App\Exceptions\Maintenance\InvalidMaintenanceTransitionException;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminMachineService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| AdminMachineService — unit
|--------------------------------------------------------------------------
|
| Covers every public method per docs/architecture/admin-machines-
| maintenance.md § 10.1.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->service = app(AdminMachineService::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function svcMachine(array $overrides = []): Machine
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();

    return Machine::factory()->forUser($customer)->forProduct($product)
        ->state(array_merge(['address_id' => $address->id, 'country_code' => 'ae'], $overrides))
        ->create();
}

describe('AdminMachineService::list()', function (): void {
    it('paginates with no filters', function (): void {
        svcMachine();
        svcMachine();

        $paginator = $this->service->list([], 1, 50);
        expect($paginator->total())->toBe(2);
    });

    it('filters by serial search (LIKE)', function (): void {
        svcMachine(['serial_number' => 'NEEDLE-1']);
        svcMachine(['serial_number' => 'OTHER-1']);

        expect($this->service->list(['search' => 'NEEDLE'], 1, 50)->total())->toBe(1);
    });

    it('filters by country_code', function (): void {
        $qa = Country::query()->where('code', 'qa')->firstOrFail();
        svcMachine(['country_code' => 'ae']);
        svcMachine(['country_code' => 'qa']);

        expect($this->service->list(['country_code' => 'qa'], 1, 50)->total())->toBe(1);
    });

    it('filters by is_active=false', function (): void {
        svcMachine(['is_active' => true]);
        svcMachine(['is_active' => false]);

        expect($this->service->list(['is_active' => false], 1, 50)->total())->toBe(1);
    });

    it('filters by needs_maintenance within default 30 days', function (): void {
        svcMachine(['next_maintenance_due_at' => now()->addDays(5)->toDateString()]);
        svcMachine(['next_maintenance_due_at' => now()->addDays(120)->toDateString()]);

        expect($this->service->list(['needs_maintenance' => true], 1, 50)->total())->toBe(1);
    });

    it('filters by source and term', function (): void {
        svcMachine(['source' => MachineSource::Imported->value, 'term' => ProductTerm::Rent->value]);
        svcMachine(['source' => MachineSource::Created->value, 'term' => ProductTerm::Buy->value]);

        expect($this->service->list(['source' => 'imported'], 1, 50)->total())->toBe(1);
        expect($this->service->list(['term' => 'buy'], 1, 50)->total())->toBe(1);
    });

    it('filters by product_id and customer_id', function (): void {
        $machineA = svcMachine();
        svcMachine();

        expect($this->service->list(['product_id' => $machineA->product_id], 1, 50)->total())->toBe(1);
        expect($this->service->list(['customer_id' => $machineA->user_id], 1, 50)->total())->toBe(1);
    });

    it('includes trashed when include_trashed=true', function (): void {
        $m = svcMachine();
        $m->delete();
        svcMachine();

        expect($this->service->list([], 1, 50)->total())->toBe(1);
        expect($this->service->list(['include_trashed' => true], 1, 50)->total())->toBe(2);
    });

    it('falls back to created_at on unknown sort column', function (): void {
        svcMachine();
        svcMachine();

        $paginator = $this->service->list(['sort' => 'totally_unknown'], 1, 50);
        expect($paginator->total())->toBe(2);
    });
});

describe('AdminMachineService::find()', function (): void {
    it('throws ModelNotFoundException on unknown id', function (): void {
        $this->service->find(9999999);
    })->throws(ModelNotFoundException::class);

    it('returns a soft-deleted machine (admins can audit trashed)', function (): void {
        $machine = svcMachine();
        $machine->delete();

        $found = $this->service->find($machine->id);
        expect($found->id)->toBe($machine->id)
            ->and($found->trashed())->toBeTrue();
    });

    it('eager-loads relations + counts', function (): void {
        $machine = svcMachine();
        $found = $this->service->find($machine->id);

        expect($found->relationLoaded('user'))->toBeTrue();
        expect($found->relationLoaded('product'))->toBeTrue();
        expect($found->relationLoaded('address'))->toBeTrue();
        expect($found->relationLoaded('maintenanceLogs'))->toBeTrue();
        expect($found->relationLoaded('maintenanceRequests'))->toBeTrue();
    });
});

describe('AdminMachineService::create()', function (): void {
    it('creates a machine and stamps source=created, order_id=null', function (): void {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $product = Product::factory()->create();

        $machine = $this->service->create([
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'product_id' => $product->id,
            'serial_number' => 'SVC-CREATE-1',
            'term' => ProductTerm::Buy->value,
            'installed_at' => now()->subMonth()->toDateString(),
            'filter_change_interval_months' => 6,
            'country_code' => 'ae',
        ], $admin, '127.0.0.1');

        expect($machine->source)->toBe(MachineSource::Created)
            ->and($machine->order_id)->toBeNull()
            ->and($machine->serial_number)->toBe('SVC-CREATE-1')
            ->and($machine->next_maintenance_due_at)->not->toBeNull();

        $entry = Activity::query()->where('log_name', 'admin.machines')
            ->where('event', 'admin.machine.created')->latest('id')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['ip'])->toBe('127.0.0.1');
    });

    it('skips auto-recompute when next_maintenance_due_at is supplied', function (): void {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $product = Product::factory()->create();

        $explicit = now()->addDays(20)->toDateString();
        $machine = $this->service->create([
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'product_id' => $product->id,
            'serial_number' => 'SVC-EXPLICIT-1',
            'term' => ProductTerm::Buy->value,
            'installed_at' => now()->subMonth()->toDateString(),
            'filter_change_interval_months' => 12,
            'next_maintenance_due_at' => $explicit,
            'country_code' => 'ae',
        ], $admin);

        expect($machine->next_maintenance_due_at->toDateString())->toBe($explicit);
    });

    it('leaves next_maintenance_due_at null when no interval and no override', function (): void {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $product = Product::factory()->create();

        $machine = $this->service->create([
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'product_id' => $product->id,
            'serial_number' => 'SVC-NULL-DUE-1',
            'term' => ProductTerm::Buy->value,
            'installed_at' => now()->subMonth()->toDateString(),
            'country_code' => 'ae',
        ], $admin);

        expect($machine->next_maintenance_due_at)->toBeNull();
    });
});

describe('AdminMachineService::update()', function (): void {
    it('updates fields and writes the admin.machines activity row', function (): void {
        $admin = User::factory()->admin()->create();
        $machine = svcMachine(['serial_number' => 'BEFORE']);

        $updated = $this->service->update($machine, [
            'serial_number' => 'AFTER',
            'notes' => 'Edited by admin',
        ], $admin, '10.0.0.1');

        expect($updated->serial_number)->toBe('AFTER')
            ->and($updated->notes)->toBe('Edited by admin');

        $entry = Activity::query()->where('log_name', 'admin.machines')
            ->where('event', 'admin.machine.updated')->latest('id')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['ip'])->toBe('10.0.0.1');
    });

    it('recomputes next_maintenance_due_at when interval changes and no explicit override', function (): void {
        $admin = User::factory()->admin()->create();
        $machine = svcMachine([
            'installed_at' => now()->subMonths(2)->toDateString(),
            'filter_change_interval_months' => 12,
            'next_maintenance_due_at' => now()->addMonths(10)->toDateString(),
        ]);
        $original = $machine->next_maintenance_due_at?->toDateString();

        $updated = $this->service->update($machine, [
            'filter_change_interval_months' => 3,
        ], $admin);

        expect($updated->filter_change_interval_months)->toBe(3);
        expect($updated->next_maintenance_due_at?->toDateString())->not->toBe($original);
    });

    it('honours explicit next_maintenance_due_at override (scheduler bypassed)', function (): void {
        $admin = User::factory()->admin()->create();
        $machine = svcMachine([
            'installed_at' => now()->subMonths(2)->toDateString(),
            'filter_change_interval_months' => 12,
        ]);
        $explicit = now()->addDays(15)->toDateString();

        $updated = $this->service->update($machine, [
            'filter_change_interval_months' => 6,
            'next_maintenance_due_at' => $explicit,
        ], $admin);

        expect($updated->next_maintenance_due_at->toDateString())->toBe($explicit);
    });

    it('rejects updates on trashed rows with InvalidMaintenanceTransitionException', function (): void {
        $admin = User::factory()->admin()->create();
        $machine = svcMachine();
        $machine->delete();

        $this->service->update($machine, ['notes' => 'fail'], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);
});

describe('AdminMachineService::dueMaintenance()', function (): void {
    it('returns only machines due within the window, sorted ASC by next due', function (): void {
        $early = svcMachine(['next_maintenance_due_at' => now()->addDays(2)->toDateString()]);
        $later = svcMachine(['next_maintenance_due_at' => now()->addDays(20)->toDateString()]);
        svcMachine(['next_maintenance_due_at' => now()->addDays(90)->toDateString()]); // outside default 30

        $paginator = $this->service->dueMaintenance(['days' => 30], 1, 50);
        expect($paginator->total())->toBe(2);

        $ids = array_map(static fn (Machine $m) => $m->id, $paginator->items());
        expect($ids[0])->toBe($early->id);
        expect($ids[1])->toBe($later->id);
    });

    it('honours within_days alias', function (): void {
        svcMachine(['next_maintenance_due_at' => now()->addDays(45)->toDateString()]);

        expect($this->service->dueMaintenance(['within_days' => 60], 1, 50)->total())->toBe(1);
        expect($this->service->dueMaintenance(['within_days' => 30], 1, 50)->total())->toBe(0);
    });

    it('filters by country_code and customer_id', function (): void {
        $m1 = svcMachine(['country_code' => 'ae', 'next_maintenance_due_at' => now()->addDays(5)->toDateString()]);
        svcMachine(['country_code' => 'qa', 'next_maintenance_due_at' => now()->addDays(5)->toDateString()]);

        expect($this->service->dueMaintenance(['country_code' => 'ae'], 1, 50)->total())->toBe(1);
        expect($this->service->dueMaintenance(['customer_id' => $m1->user_id], 1, 50)->total())->toBe(1);
    });
});
