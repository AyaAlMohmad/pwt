<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Exceptions\Maintenance\InvalidMaintenanceTransitionException;
use App\Models\Address;
use App\Models\Country;
use App\Models\File;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Maintenance\MaintenanceRequestService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
|--------------------------------------------------------------------------
| MaintenanceRequestService — service-level tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/machines-maintenance.md § 2 + § 11.2.
| Drives:
|   - submit() : creates request + writes initial history row + media pivots
|   - listForUser() : applies filters (status, date_from, date_to)
|   - cancel() : customer-only from `new`; admin path from broader states
|   - transitionTo() : generic state-machine entrypoint (matrix below)
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->service = new MaintenanceRequestService;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, machine: Machine}
 */
function svcMakeCustomerMachine(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($user)->forProduct($product)
        ->state(['address_id' => $address->id, 'is_active' => true])->create();

    return ['user' => $user, 'machine' => $machine];
}

/*
|--------------------------------------------------------------------------
| submit()
|--------------------------------------------------------------------------
*/

describe('MaintenanceRequestService::submit()', function (): void {
    it('writes the request, the initial status_history row, and attaches media pivots', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
        $file1 = File::factory()->for($user)->forType(FileType::MaintenanceMedia)->create();
        $file2 = File::factory()->for($user)->forType(FileType::MaintenanceMedia)->create();

        $request = $this->service->submit($user, [
            'machine_id' => $machine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'Visible leak at the unit base.',
            'preferred_date' => now()->addDays(2)->toDateString(),
            'priority' => MaintenancePriority::Medium->value,
            'media_file_ids' => [$file1->id, $file2->id],
        ]);

        expect($request->user_id)->toBe($user->id);
        expect($request->machine_id)->toBe($machine->id);
        expect($request->status)->toBe(MaintenanceStatus::New);
        expect($request->priority)->toBe(MaintenancePriority::Medium);

        $this->assertDatabaseHas('maintenance_status_history', [
            'maintenance_request_id' => $request->id,
            'from_status' => null,
            'to_status' => MaintenanceStatus::New->value,
            'actor_type' => MaintenanceActorType::Customer->value,
            'actor_id' => $user->id,
        ]);

        $this->assertDatabaseHas('maintenance_request_media', [
            'maintenance_request_id' => $request->id,
            'file_id' => $file1->id,
        ]);
        $this->assertDatabaseHas('maintenance_request_media', [
            'maintenance_request_id' => $request->id,
            'file_id' => $file2->id,
        ]);
    });

    it('defaults priority to medium when not supplied', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();

        $request = $this->service->submit($user, [
            'machine_id' => $machine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'Visible leak at the unit base.',
        ]);

        expect($request->priority)->toBe(MaintenancePriority::Medium);
    });

    it('coerces high / urgent customer priority down to medium', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();

        $high = $this->service->submit($user, [
            'machine_id' => $machine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'Visible leak at the unit base.',
            'priority' => 'high',
        ]);
        $urgent = $this->service->submit($user, [
            'machine_id' => $machine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'Visible leak at the unit base.',
            'priority' => 'urgent',
        ]);

        expect($high->priority)->toBe(MaintenancePriority::Medium);
        expect($urgent->priority)->toBe(MaintenancePriority::Medium);
    });

    it('keeps low priority as low', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();

        $request = $this->service->submit($user, [
            'machine_id' => $machine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'Visible leak at the unit base.',
            'priority' => MaintenancePriority::Low->value,
        ]);

        expect($request->priority)->toBe(MaintenancePriority::Low);
    });

    it('throws ModelNotFoundException when machine is not owned by the user', function (): void {
        $owner = User::factory()->customer()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();
        $product = Product::factory()->create();
        $foreignMachine = Machine::factory()->forUser($owner)->forProduct($product)
            ->state(['address_id' => $address->id])->create();

        $stranger = User::factory()->customer()->create();

        $this->service->submit($stranger, [
            'machine_id' => $foreignMachine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'Not mine to claim against.',
        ]);
    })->throws(ModelNotFoundException::class);
});

/*
|--------------------------------------------------------------------------
| listForUser()
|--------------------------------------------------------------------------
*/

describe('MaintenanceRequestService::listForUser()', function (): void {
    it('returns the caller\'s requests only', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
        ['user' => $stranger, 'machine' => $strangerMachine] = svcMakeCustomerMachine();

        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->count(2)->create();
        MaintenanceRequest::factory()->forUser($stranger)->forMachine($strangerMachine)->create();

        $page = $this->service->listForUser($user, [], 1, 15);

        expect($page->total())->toBe(2);
    });

    it('filters by status', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->newStatus()->create();
        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->completed()->create();

        $page = $this->service->listForUser($user, ['status' => 'new'], 1, 15);

        expect($page->total())->toBe(1);
        expect($page->items()[0]->status)->toBe(MaintenanceStatus::New);
    });

    it('filters by date_from/date_to (inclusive)', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
        $inWindow = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state(['created_at' => now()->subDays(2)])->create();
        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state(['created_at' => now()->subDays(40)])->create();

        $page = $this->service->listForUser($user, [
            'date_from' => now()->subDays(10)->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ], 1, 15);

        expect($page->total())->toBe(1);
        expect($page->items()[0]->id)->toBe($inWindow->id);
    });
});

/*
|--------------------------------------------------------------------------
| cancel() — customer path is only from `new`
|--------------------------------------------------------------------------
*/

describe('MaintenanceRequestService::cancel()', function (): void {
    it('cancels a `new` request when the actor is the owner (customer path)', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->newStatus()->create();

        $cancelled = $this->service->cancel($request, $user, 'No longer needed');

        expect($cancelled->status)->toBe(MaintenanceStatus::Cancelled);
        expect($cancelled->cancellation_reason)->toBe('No longer needed');
        expect($cancelled->cancelled_by_user_id)->toBe($user->id);
        expect($cancelled->closed_at)->not->toBeNull();
    });

    it('throws InvalidMaintenanceTransitionException when customer cancels a non-new request', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->assigned()->create();

        $this->service->cancel($request, $user, 'Trying anyway');
    })->throws(InvalidMaintenanceTransitionException::class);

    it('throws InvalidMaintenanceTransitionException when customer cancels a completed request', function (): void {
        ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->completed()->create();

        $this->service->cancel($request, $user, 'Trying anyway');
    })->throws(InvalidMaintenanceTransitionException::class);
});

/*
|--------------------------------------------------------------------------
| transitionTo() — full state-machine matrix
|--------------------------------------------------------------------------
*/

$matrix = [
    // new → ...
    [MaintenanceStatus::New, MaintenanceStatus::Assigned, true],
    [MaintenanceStatus::New, MaintenanceStatus::Cancelled, true],
    [MaintenanceStatus::New, MaintenanceStatus::InProgress, false],
    [MaintenanceStatus::New, MaintenanceStatus::Completed, false],
    // assigned → ...
    [MaintenanceStatus::Assigned, MaintenanceStatus::InProgress, true],
    [MaintenanceStatus::Assigned, MaintenanceStatus::Cancelled, true],
    [MaintenanceStatus::Assigned, MaintenanceStatus::New, false],
    [MaintenanceStatus::Assigned, MaintenanceStatus::Completed, false],
    // in_progress → ...
    [MaintenanceStatus::InProgress, MaintenanceStatus::Completed, true],
    [MaintenanceStatus::InProgress, MaintenanceStatus::Cancelled, true],
    [MaintenanceStatus::InProgress, MaintenanceStatus::New, false],
    [MaintenanceStatus::InProgress, MaintenanceStatus::Assigned, false],
    // completed → terminal
    [MaintenanceStatus::Completed, MaintenanceStatus::New, false],
    [MaintenanceStatus::Completed, MaintenanceStatus::Assigned, false],
    [MaintenanceStatus::Completed, MaintenanceStatus::InProgress, false],
    [MaintenanceStatus::Completed, MaintenanceStatus::Cancelled, false],
    // cancelled → terminal
    [MaintenanceStatus::Cancelled, MaintenanceStatus::New, false],
    [MaintenanceStatus::Cancelled, MaintenanceStatus::Assigned, false],
    [MaintenanceStatus::Cancelled, MaintenanceStatus::InProgress, false],
    [MaintenanceStatus::Cancelled, MaintenanceStatus::Completed, false],
];

function makeRequestInState(MaintenanceStatus $state): array
{
    ['user' => $user, 'machine' => $machine] = svcMakeCustomerMachine();
    $factory = MaintenanceRequest::factory()->forUser($user)->forMachine($machine);
    $factory = match ($state) {
        MaintenanceStatus::New => $factory->newStatus(),
        MaintenanceStatus::Assigned => $factory->assigned(),
        MaintenanceStatus::InProgress => $factory->inProgress(),
        MaintenanceStatus::Completed => $factory->completed(),
        MaintenanceStatus::Cancelled => $factory->cancelled(),
    };

    return [$user, $factory->create()];
}

describe('MaintenanceRequestService::transitionTo() — allowed transitions', function () use ($matrix): void {
    foreach (array_filter($matrix, fn ($r) => $r[2] === true) as $row) {
        [$from, $to] = $row;
        $label = $from->value.' → '.$to->value;

        it("allows $label and writes a history row", function () use ($from, $to): void {
            [$user, $request] = makeRequestInState($from);
            $admin = User::factory()->admin()->create();

            $updated = $this->service->transitionTo(
                $request,
                $to,
                $admin,
                MaintenanceActorType::Admin,
                ['note' => 'matrix test'],
            );

            expect($updated->status)->toBe($to);

            $this->assertDatabaseHas('maintenance_status_history', [
                'maintenance_request_id' => $request->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => MaintenanceActorType::Admin->value,
                'note' => 'matrix test',
            ]);
        });
    }
});

describe('MaintenanceRequestService::transitionTo() — disallowed transitions', function () use ($matrix): void {
    foreach (array_filter($matrix, fn ($r) => $r[2] === false) as $row) {
        [$from, $to] = $row;
        $label = $from->value.' → '.$to->value;

        it("rejects $label with InvalidMaintenanceTransitionException (409)", function () use ($from, $to): void {
            [$user, $request] = makeRequestInState($from);
            $admin = User::factory()->admin()->create();

            $thrown = null;
            try {
                $this->service->transitionTo(
                    $request,
                    $to,
                    $admin,
                    MaintenanceActorType::Admin,
                    [],
                );
            } catch (InvalidMaintenanceTransitionException $e) {
                $thrown = $e;
            }

            expect($thrown)->not->toBeNull();
            expect($thrown->status)->toBe(409);
            expect($thrown->errorCode)->toBe('ERR_INVALID_STATUS_TRANSITION');

            $request->refresh();
            expect($request->status)->toBe($from);
        });
    }
});

/*
|--------------------------------------------------------------------------
| Side-effects on terminal transitions
|--------------------------------------------------------------------------
*/

describe('MaintenanceRequestService::transitionTo() — side-effects', function (): void {
    it('sets closed_at on the cancelled transition', function (): void {
        [$user, $request] = makeRequestInState(MaintenanceStatus::New);
        $admin = User::factory()->admin()->create();

        expect($request->closed_at)->toBeNull();

        $updated = $this->service->transitionTo(
            $request,
            MaintenanceStatus::Cancelled,
            $admin,
            MaintenanceActorType::Admin,
            ['note' => 'sla breach'],
        );

        expect($updated->closed_at)->not->toBeNull();
    });

    it('sets closed_at AND resolution_notes on the completed transition', function (): void {
        [$user, $request] = makeRequestInState(MaintenanceStatus::InProgress);
        $admin = User::factory()->admin()->create();

        $updated = $this->service->transitionTo(
            $request,
            MaintenanceStatus::Completed,
            $admin,
            MaintenanceActorType::Admin,
            ['resolution_notes' => 'Filter replaced, flow verified.'],
        );

        expect($updated->closed_at)->not->toBeNull();
        expect($updated->resolution_notes)->toBe('Filter replaced, flow verified.');
    });
});
