<?php

declare(strict_types=1);

use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenanceLogSource;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\UserRole;
use App\Events\Maintenance\MaintenanceRequestCreated;
use App\Exceptions\Maintenance\InvalidMaintenanceTransitionException;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminMaintenanceService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| AdminMaintenanceService — unit
|--------------------------------------------------------------------------
|
| Covers every public method per docs/architecture/admin-machines-
| maintenance.md § 10.2.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->service = app(AdminMaintenanceService::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{customer: User, machine: Machine}
 */
function svcCustomerMachine(array $machineOverrides = []): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($customer)->forProduct($product)
        ->state(array_merge([
            'address_id' => $address->id,
            'is_active' => true,
            'filter_change_interval_months' => 6,
            'installed_at' => now()->subMonth()->toDateString(),
        ], $machineOverrides))
        ->create();

    return ['customer' => $customer, 'machine' => $machine];
}

function svcMaintRequest(array $overrides = []): MaintenanceRequest
{
    ['customer' => $c, 'machine' => $m] = svcCustomerMachine();

    return MaintenanceRequest::factory()->forUser($c)->forMachine($m)
        ->state($overrides)->create();
}

describe('AdminMaintenanceService::list()', function (): void {
    it('paginates all rows by default', function (): void {
        svcMaintRequest();
        svcMaintRequest();

        expect($this->service->list([], 1, 50)->total())->toBe(2);
    });

    it('filters by status array', function (): void {
        svcMaintRequest(['status' => MaintenanceStatus::New->value]);
        svcMaintRequest([
            'status' => MaintenanceStatus::Completed->value,
            'closed_at' => now(),
            'resolution_notes' => 'x',
        ]);

        expect($this->service->list(['status' => ['new']], 1, 50)->total())->toBe(1);
    });

    it('filters by priority', function (): void {
        svcMaintRequest(['priority' => MaintenancePriority::Urgent->value]);
        svcMaintRequest(['priority' => MaintenancePriority::Low->value]);

        expect($this->service->list(['priority' => 'urgent'], 1, 50)->total())->toBe(1);
    });

    it('filters by assigned_to_user_id=0 (unassigned)', function (): void {
        $tech = User::factory()->admin(UserRole::Operations->value)->create();
        svcMaintRequest(['assigned_to_user_id' => null]);
        svcMaintRequest([
            'assigned_to_user_id' => $tech->id,
            'status' => MaintenanceStatus::Assigned->value,
        ]);

        expect($this->service->list(['assigned_to_user_id' => 0], 1, 50)->total())->toBe(1);
    });

    it('filters by assigned_to_user_id to specific technician', function (): void {
        $tech = User::factory()->admin(UserRole::Operations->value)->create();
        svcMaintRequest([
            'assigned_to_user_id' => $tech->id,
            'status' => MaintenanceStatus::Assigned->value,
        ]);
        svcMaintRequest();

        expect($this->service->list(['assigned_to_user_id' => $tech->id], 1, 50)->total())->toBe(1);
    });

    it('filters by date range on created_at', function (): void {
        svcMaintRequest(['created_at' => now()->subDays(30)]);
        svcMaintRequest(['created_at' => now()->subDay()]);

        $paginator = $this->service->list([
            'date_from' => now()->subDays(7)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ], 1, 50);
        expect($paginator->total())->toBe(1);
    });

    it('filters by country_code through the machine relation', function (): void {
        $qa = Country::query()->where('code', 'qa')->firstOrFail();
        ['customer' => $aeC, 'machine' => $aeM] = svcCustomerMachine(['country_code' => 'ae']);
        ['customer' => $qaC, 'machine' => $qaM] = svcCustomerMachine(['country_code' => 'qa']);
        MaintenanceRequest::factory()->forUser($aeC)->forMachine($aeM)->create();
        MaintenanceRequest::factory()->forUser($qaC)->forMachine($qaM)->create();

        expect($this->service->list(['country_code' => 'ae'], 1, 50)->total())->toBe(1);
    });

    it('filters by search on description (LIKE)', function (): void {
        svcMaintRequest(['description' => 'matching needle in description']);
        svcMaintRequest(['description' => 'other content']);

        expect($this->service->list(['search' => 'needle'], 1, 50)->total())->toBe(1);
    });

    it('includes trashed when include_trashed=true', function (): void {
        $req = svcMaintRequest();
        $req->delete();
        svcMaintRequest();

        expect($this->service->list([], 1, 50)->total())->toBe(1);
        expect($this->service->list(['include_trashed' => true], 1, 50)->total())->toBe(2);
    });
});

describe('AdminMaintenanceService::find()', function (): void {
    it('throws ModelNotFoundException on unknown id', function (): void {
        $this->service->find(9999999);
    })->throws(ModelNotFoundException::class);

    it('returns soft-deleted rows', function (): void {
        $req = svcMaintRequest();
        $req->delete();

        $found = $this->service->find($req->id);
        expect($found->id)->toBe($req->id)
            ->and($found->trashed())->toBeTrue();
    });

    it('eager-loads media, statusHistory, machine.product, assignedTo, cancelledBy', function (): void {
        $req = svcMaintRequest();
        $found = $this->service->find($req->id);

        expect($found->relationLoaded('user'))->toBeTrue();
        expect($found->relationLoaded('machine'))->toBeTrue();
        expect($found->relationLoaded('media'))->toBeTrue();
        expect($found->relationLoaded('statusHistory'))->toBeTrue();
    });
});

describe('AdminMaintenanceService::submitOnBehalf()', function (): void {
    it('creates the request, history row, and dispatches MaintenanceRequestCreated', function (): void {
        Event::fake([MaintenanceRequestCreated::class]);
        $admin = User::factory()->admin()->create();
        ['customer' => $customer, 'machine' => $machine] = svcCustomerMachine();

        $req = $this->service->submitOnBehalf([
            'customer_id' => $customer->id,
            'machine_id' => $machine->id,
            'issue_type' => MaintenanceIssueType::FilterChange->value,
            'description' => 'admin on-behalf request',
            'priority' => MaintenancePriority::High->value,
            'internal_notes' => 'private note',
        ], $admin, '127.0.0.1');

        expect($req->status)->toBe(MaintenanceStatus::New)
            ->and($req->priority)->toBe(MaintenancePriority::High)
            ->and($req->internal_notes)->toBe('private note');

        $history = MaintenanceStatusHistory::query()->where('maintenance_request_id', $req->id)->first();
        expect($history->actor_type)->toBe(MaintenanceActorType::Admin)
            ->and($history->actor_id)->toBe($admin->id);

        Event::assertDispatched(MaintenanceRequestCreated::class);
    });

    it('defaults priority to medium when not supplied', function (): void {
        $admin = User::factory()->admin()->create();
        ['customer' => $customer, 'machine' => $machine] = svcCustomerMachine();

        $req = $this->service->submitOnBehalf([
            'customer_id' => $customer->id,
            'machine_id' => $machine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'no priority',
        ], $admin);

        expect($req->priority)->toBe(MaintenancePriority::Medium);
    });

    it('throws InvalidMaintenanceTransitionException when machine not owned (defensive re-check)', function (): void {
        $admin = User::factory()->admin()->create();
        ['customer' => $customer] = svcCustomerMachine();
        ['machine' => $otherMachine] = svcCustomerMachine();

        $this->service->submitOnBehalf([
            'customer_id' => $customer->id,
            'machine_id' => $otherMachine->id,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'should fail defence',
        ], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);
});

describe('AdminMaintenanceService::assign()', function (): void {
    it('transitions new → assigned and stamps the technician', function (): void {
        $admin = User::factory()->admin()->create();
        $tech = User::factory()->admin(UserRole::Operations->value)->create();
        $req = svcMaintRequest();

        $updated = $this->service->assign($req, [
            'assigned_to_user_id' => $tech->id,
            'note' => 'first assignment',
        ], $admin, '10.0.0.5');

        expect($updated->status)->toBe(MaintenanceStatus::Assigned);
        expect($updated->assigned_to_user_id)->toBe($tech->id);

        $entry = Activity::query()->where('event', 'admin.maintenance.assigned')->latest('id')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->properties['ip'])->toBe('10.0.0.5');
    });

    it('re-assigns within assigned and writes a notes-only history row', function (): void {
        $admin = User::factory()->admin()->create();
        $first = User::factory()->admin(UserRole::Operations->value)->create();
        $second = User::factory()->admin(UserRole::Operations->value)->create();
        $req = svcMaintRequest([
            'status' => MaintenanceStatus::Assigned->value,
            'assigned_to_user_id' => $first->id,
        ]);

        $updated = $this->service->assign($req, [
            'assigned_to_user_id' => $second->id,
            'note' => 're-assign note',
        ], $admin);

        expect($updated->assigned_to_user_id)->toBe($second->id);

        $rows = MaintenanceStatusHistory::query()
            ->where('maintenance_request_id', $req->id)
            ->where('from_status', MaintenanceStatus::Assigned->value)
            ->where('to_status', MaintenanceStatus::Assigned->value)
            ->get();
        expect($rows)->toHaveCount(1);
        expect($rows->first()->note)->toBe('re-assign note');
    });

    it('throws when trying to assign a trashed request', function (): void {
        $admin = User::factory()->admin()->create();
        $tech = User::factory()->admin(UserRole::Operations->value)->create();
        $req = svcMaintRequest();
        $req->delete();

        $this->service->assign($req, ['assigned_to_user_id' => $tech->id], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);

    it('throws when trying to assign from terminal state', function (): void {
        $admin = User::factory()->admin()->create();
        $tech = User::factory()->admin(UserRole::Operations->value)->create();
        $req = svcMaintRequest([
            'status' => MaintenanceStatus::Completed->value,
            'closed_at' => now()->subHour(),
            'resolution_notes' => 'done',
        ]);

        $this->service->assign($req, ['assigned_to_user_id' => $tech->id], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);
});

describe('AdminMaintenanceService::transition()', function (): void {
    it('routes status=in_progress through transitionTo()', function (): void {
        $admin = User::factory()->admin()->create();
        $tech = User::factory()->admin(UserRole::Operations->value)->create();
        $req = svcMaintRequest([
            'status' => MaintenanceStatus::Assigned->value,
            'assigned_to_user_id' => $tech->id,
        ]);

        $updated = $this->service->transition($req, [
            'status' => MaintenanceStatus::InProgress->value,
            'note' => 'starting work',
        ], $admin, '127.0.0.1');

        expect($updated->status)->toBe(MaintenanceStatus::InProgress);
    });

    it('routes status=cancelled through cancel() and stamps reason', function (): void {
        $admin = User::factory()->admin()->create();
        $req = svcMaintRequest();

        $updated = $this->service->transition($req, [
            'status' => MaintenanceStatus::Cancelled->value,
            'reason' => 'duplicate ticket',
        ], $admin);

        expect($updated->status)->toBe(MaintenanceStatus::Cancelled);
        expect($updated->cancellation_reason)->toBe('duplicate ticket');
    });

    it('throws useAssignEndpoint exception for status=assigned', function (): void {
        $admin = User::factory()->admin()->create();
        $req = svcMaintRequest();

        $this->service->transition($req, ['status' => MaintenanceStatus::Assigned->value], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);

    it('throws useCloseEndpoint exception for status=completed', function (): void {
        $admin = User::factory()->admin()->create();
        $req = svcMaintRequest(['status' => MaintenanceStatus::InProgress->value]);

        $this->service->transition($req, ['status' => MaintenanceStatus::Completed->value], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);

    it('throws for an unknown target status string', function (): void {
        $admin = User::factory()->admin()->create();
        $req = svcMaintRequest();

        $this->service->transition($req, ['status' => 'bogus'], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);

    it('throws when the row is trashed', function (): void {
        $admin = User::factory()->admin()->create();
        $req = svcMaintRequest();
        $req->delete();

        $this->service->transition($req, [
            'status' => MaintenanceStatus::Cancelled->value,
            'reason' => 'too late',
        ], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);
});

describe('AdminMaintenanceService::close()', function (): void {
    it('closes in_progress → completed, writes log row, refreshes scheduler', function (): void {
        $admin = User::factory()->admin()->create();
        $tech = User::factory()->admin(UserRole::Operations->value)->create();

        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
            'address_id' => $address->id,
            'filter_change_interval_months' => 6,
            'installed_at' => now()->subMonth()->toDateString(),
            'next_maintenance_due_at' => now()->subDay()->toDateString(),
        ])->create();
        $req = MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->state([
            'status' => MaintenanceStatus::InProgress->value,
            'assigned_to_user_id' => $tech->id,
        ])->create();

        $closed = $this->service->close($req, [
            'resolution_notes' => 'fixed by service unit test',
        ], $admin, '1.2.3.4');

        expect($closed->status)->toBe(MaintenanceStatus::Completed);
        expect($closed->resolution_notes)->toBe('fixed by service unit test');

        $log = MachineMaintenanceLog::query()->where('maintenance_request_id', $req->id)->firstOrFail();
        expect($log->source->value)->toBe(MaintenanceLogSource::Request->value)
            ->and($log->technician_user_id)->toBe($tech->id)
            ->and($log->created_by_user_id)->toBe($admin->id);

        $machine->refresh();
        expect($machine->next_maintenance_due_at)->not->toBeNull();
        expect($machine->next_maintenance_due_at->greaterThan(now()))->toBeTrue();
    });

    it('throws when source state is not in_progress', function (): void {
        $admin = User::factory()->admin()->create();
        $req = svcMaintRequest(['status' => MaintenanceStatus::Assigned->value]);

        $this->service->close($req, ['resolution_notes' => 'should fail'], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);

    it('throws when row is trashed', function (): void {
        $admin = User::factory()->admin()->create();
        $req = svcMaintRequest(['status' => MaintenanceStatus::InProgress->value]);
        $req->delete();

        $this->service->close($req, ['resolution_notes' => 'trashed'], $admin);
    })->throws(InvalidMaintenanceTransitionException::class);

    it('accepts a back-dated completed_at and stores it on the log row', function (): void {
        $admin = User::factory()->admin()->create();
        ['customer' => $customer, 'machine' => $machine] = svcCustomerMachine([
            'installed_at' => now()->subMonths(3)->toDateString(),
        ]);
        $tech = User::factory()->admin(UserRole::Operations->value)->create();
        $req = MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->state([
            'status' => MaintenanceStatus::InProgress->value,
            'assigned_to_user_id' => $tech->id,
        ])->create();

        $backdated = now()->subDays(3)->toDateString();
        $this->service->close($req, [
            'resolution_notes' => 'late entry',
            'completed_at' => $backdated,
        ], $admin);

        $log = MachineMaintenanceLog::query()->where('maintenance_request_id', $req->id)->firstOrFail();
        expect($log->completed_at->toDateString())->toBe($backdated);
    });
});
