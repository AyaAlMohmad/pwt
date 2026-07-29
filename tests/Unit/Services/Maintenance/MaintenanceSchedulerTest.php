<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\Product;
use App\Models\User;
use App\Services\Maintenance\MaintenanceScheduler;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| MaintenanceScheduler — unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/machines-maintenance.md § 11.1.
|   baseline = max(installed_at, latest_completed_log.completed_at)
|   next_due = baseline + filter_change_interval_months months
|
| `dueWithinDays($days)` returns the Eloquent builder for active +
| non-soft-deleted machines whose `next_maintenance_due_at` <= now+$days.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->scheduler = new MaintenanceScheduler;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function schedulerMakeMachine(array $overrides = []): Machine
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $product = Product::factory()->create();

    return Machine::factory()->forUser($user)->forProduct($product)
        ->state(array_merge(['address_id' => $address->id], $overrides))
        ->create();
}

describe('MaintenanceScheduler::computeNextDue()', function (): void {
    it('returns installed_at + interval when there are no completed logs', function (): void {
        $installed = Carbon::parse('2026-01-15');
        $machine = schedulerMakeMachine([
            'installed_at' => $installed->toDateString(),
            'filter_change_interval_months' => 3,
        ]);

        $result = $this->scheduler->computeNextDue($machine);

        expect($result)->not->toBeNull();
        expect($result->toDateString())->toBe('2026-04-15');
    });

    it('returns latest_completed_log.completed_at + interval when a completed log exists', function (): void {
        $installed = Carbon::parse('2025-12-01');
        $machine = schedulerMakeMachine([
            'installed_at' => $installed->toDateString(),
            'filter_change_interval_months' => 3,
        ]);

        // Add an older completed log + a more recent completed log.
        MachineMaintenanceLog::factory()->forMachine($machine)->completed()
            ->state([
                'scheduled_at' => '2026-02-01',
                'completed_at' => '2026-02-01',
            ])->create();
        MachineMaintenanceLog::factory()->forMachine($machine)->completed()
            ->state([
                'scheduled_at' => '2026-04-01',
                'completed_at' => '2026-04-01',
            ])->create();

        $result = $this->scheduler->computeNextDue($machine);

        expect($result)->not->toBeNull();
        expect($result->toDateString())->toBe('2026-07-01');
    });

    it('returns null when filter_change_interval_months is null', function (): void {
        $machine = schedulerMakeMachine([
            'filter_change_interval_months' => null,
        ]);

        $result = $this->scheduler->computeNextDue($machine);

        expect($result)->toBeNull();
    });

    it('returns null when filter_change_interval_months is zero (invalid interval)', function (): void {
        $machine = schedulerMakeMachine([
            'filter_change_interval_months' => 0,
        ]);

        $result = $this->scheduler->computeNextDue($machine);

        expect($result)->toBeNull();
    });

    it('uses installed_at when a completed log exists BEFORE installation', function (): void {
        // Defensive edge case — completed_at < installed_at (data quirk).
        $machine = schedulerMakeMachine([
            'installed_at' => '2026-03-01',
            'filter_change_interval_months' => 3,
        ]);
        MachineMaintenanceLog::factory()->forMachine($machine)->completed()
            ->state([
                'scheduled_at' => '2026-01-01',
                'completed_at' => '2026-01-01',
            ])->create();

        $result = $this->scheduler->computeNextDue($machine);

        expect($result)->not->toBeNull();
        // baseline = max(installed_at, completed_at) = installed_at
        expect($result->toDateString())->toBe('2026-06-01');
    });

    it('honours the referenceFrom override when supplied', function (): void {
        $machine = schedulerMakeMachine([
            'installed_at' => '2026-01-01',
            'filter_change_interval_months' => 6,
        ]);

        $reference = Carbon::parse('2026-08-15');
        $result = $this->scheduler->computeNextDue($machine, $reference);

        expect($result)->not->toBeNull();
        expect($result->toDateString())->toBe('2027-02-15');
    });
});

describe('MaintenanceScheduler::dueWithinDays()', function (): void {
    afterEach(function (): void {
        Carbon::setTestNow();
    });

    it('returns machines whose next_maintenance_due_at <= NOW() + days (overdue + due-soon)', function (): void {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $overdue = schedulerMakeMachine([
            'next_maintenance_due_at' => '2026-05-01',
            'is_active' => true,
        ]);
        $dueSoon = schedulerMakeMachine([
            'next_maintenance_due_at' => '2026-05-25',
            'is_active' => true,
        ]);
        $tooFar = schedulerMakeMachine([
            'next_maintenance_due_at' => '2026-07-01',
            'is_active' => true,
        ]);
        $noDue = schedulerMakeMachine([
            'next_maintenance_due_at' => null,
            'is_active' => true,
        ]);

        $ids = $this->scheduler->dueWithinDays(30)->pluck('id')->all();

        expect($ids)->toContain($overdue->id);
        expect($ids)->toContain($dueSoon->id);
        expect($ids)->not->toContain($tooFar->id);
        expect($ids)->not->toContain($noDue->id);
    });

    it('excludes inactive machines from the dueWithinDays scope', function (): void {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $inactiveDue = schedulerMakeMachine([
            'next_maintenance_due_at' => '2026-05-10',
            'is_active' => false,
        ]);
        $activeDue = schedulerMakeMachine([
            'next_maintenance_due_at' => '2026-05-10',
            'is_active' => true,
        ]);

        $ids = $this->scheduler->dueWithinDays(30)->pluck('id')->all();

        expect($ids)->toContain($activeDue->id);
        expect($ids)->not->toContain($inactiveDue->id);
    });
});
