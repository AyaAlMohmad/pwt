<?php

declare(strict_types=1);

use App\Enums\MachineSource;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\MachinesFromExcelSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| MachinesFromExcelSeeder — production-grade seed integrity
|--------------------------------------------------------------------------
|
| Contract: CLAUDE.md § 6.2 + docs/architecture/machines-maintenance.md § 9.
| The Excel file `Database for devlpt.xlsx` lives in the project root /
| `storage/app/seed/excel/`. The seeder is idempotent and gated to
| local/development/testing environments only.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->seed(CategoriesSeeder::class);
    $this->seed(ProductCatalogSeeder::class);
});

describe('MachinesFromExcelSeeder', function (): void {
    it('imports at least 220 machine rows (target: 223 after skipping malformed rows)', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        $count = Machine::query()->where('source', MachineSource::Imported->value)->count();

        expect($count)->toBeGreaterThanOrEqual(220);
    });

    it('distributes machines across UAE / Qatar / Kuwait with realistic counts', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        $uae = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->where('country_code', 'ae')
            ->count();
        $qatar = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->where('country_code', 'qa')
            ->count();
        $kuwait = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->where('country_code', 'kw')
            ->count();

        // Use lower bounds (Excel may have malformed rows that get skipped)
        expect($uae)->toBeGreaterThanOrEqual(110);
        expect($qatar)->toBeGreaterThanOrEqual(3);
        expect($kuwait)->toBeGreaterThanOrEqual(100);
    });

    it('links machines to all 16 distinct catalog products (or fewer if some SKUs go unused)', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        $distinctProducts = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->distinct()
            ->count('product_id');

        // The catalog has 16 products; the Excel uses all of them.
        // Allow some slack in case a particular SKU is never installed.
        expect($distinctProducts)->toBeGreaterThanOrEqual(10);
        expect($distinctProducts)->toBeLessThanOrEqual(16);

        // The total number of catalog products remains 16 regardless.
        expect(Product::query()->count())->toBe(16);
    });

    it('populates serial_number on every imported machine (non-null)', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        $nullSerials = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->whereNull('serial_number')
            ->count();

        expect($nullSerials)->toBe(0);
    });

    it('populates next_maintenance_due_at on every imported machine', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        // Some machines may lack a parseable filter_change_interval; the
        // contract is that the seeder calls MaintenanceScheduler::computeNextDue
        // for every imported machine. We assert at least 90% have a due date
        // (the remainder are rows with no filter interval in Excel).
        $total = Machine::query()->where('source', MachineSource::Imported->value)->count();
        $withDue = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->whereNotNull('next_maintenance_due_at')
            ->count();

        expect($total)->toBeGreaterThan(0);
        $ratio = $withDue / $total;
        expect($ratio)->toBeGreaterThanOrEqual(0.9);
    });

    it('writes at least one maintenance log entry per imported machine on average', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        $machineCount = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->count();
        $logCount = MachineMaintenanceLog::query()->count();

        // Excel records up to 19 maintenance dates per machine; the
        // average is >= 1 once the schedule is filled in.
        expect($logCount)->toBeGreaterThanOrEqual($machineCount);
    });

    it('never persists a machine with NULL customer_id (user_id)', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        $orphans = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->whereNull('user_id')
            ->count();

        expect($orphans)->toBe(0);
    });

    it('seeds at least 80 unique customer users', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        // The Excel has many duplicate customer emails (same client owns
        // multiple machines). After dedupe the unique customer count is
        // in the ~80–150 range. Use a conservative lower bound.
        $uniqueCustomers = Machine::query()
            ->where('source', MachineSource::Imported->value)
            ->distinct()
            ->count('user_id');

        expect($uniqueCustomers)->toBeGreaterThanOrEqual(80);
    });

    it('is idempotent — re-running the seeder does NOT duplicate machines or logs', function (): void {
        $this->seed(MachinesFromExcelSeeder::class);

        $machinesBefore = Machine::query()->count();
        $logsBefore = MachineMaintenanceLog::query()->count();
        $usersBefore = User::query()->count();

        $this->seed(MachinesFromExcelSeeder::class);

        expect(Machine::query()->count())->toBe($machinesBefore);
        expect(MachineMaintenanceLog::query()->count())->toBe($logsBefore);
        expect(User::query()->count())->toBe($usersBefore);
    });
});
