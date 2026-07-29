<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Machine;
use App\Models\Order;
use App\Models\User;
use App\Services\Admin\AuditLogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| AuditLogService — unit
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md
| § 9.6 / § 9.9 / § 9.11.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AuditLogService::class);
});

function makeEntry(array $overrides = []): Activity
{
    $defaults = [
        'log_name' => 'admin.orders',
        'description' => 'order.status.updated',
        'event' => 'updated',
        'subject_type' => Order::class,
        'subject_id' => 1,
        'causer_type' => User::class,
        'causer_id' => null,
        'properties' => ['old' => ['status' => 'pending'], 'new' => ['status' => 'confirmed']],
    ];

    /** @var Activity $a */
    $a = Activity::query()->forceCreate(array_merge($defaults, $overrides));

    return $a;
}

describe('AuditLogService::list()', function (): void {
    it('paginates the full table with no filters', function (): void {
        makeEntry();
        makeEntry();
        makeEntry();

        $paginator = $this->service->list([], 1, 50);

        expect($paginator->total())->toBe(3)
            ->and(count($paginator->items()))->toBe(3);
    });

    it('orders newest first by created_at then id', function (): void {
        $first = makeEntry(['created_at' => now()->subHour()]);
        $second = makeEntry(['created_at' => now()]);

        $items = $this->service->list([], 1, 50)->items();
        expect($items[0]->id)->toBe($second->id)
            ->and($items[1]->id)->toBe($first->id);
    });

    it('filters by actor_id', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $other = User::factory()->admin(UserRole::Sales->value)->create();
        makeEntry(['causer_id' => $admin->id]);
        makeEntry(['causer_id' => $other->id]);

        $paginator = $this->service->list(['actor_id' => $other->id], 1, 50);

        expect($paginator->total())->toBe(1)
            ->and($paginator->items()[0]->causer_id)->toBe($other->id);
    });

    it('filters by log_name', function (): void {
        makeEntry(['log_name' => 'admin.orders']);
        makeEntry(['log_name' => 'report.export']);

        expect($this->service->list(['log_name' => 'report.export'], 1, 50)->total())->toBe(1);
    });

    it('action filter wins over log_name when both supplied', function (): void {
        makeEntry(['log_name' => 'admin.orders']);
        makeEntry(['log_name' => 'admin.machines']);

        $paginator = $this->service->list([
            'action' => 'admin.machines',
            'log_name' => 'admin.orders',
        ], 1, 50);

        expect($paginator->total())->toBe(1)
            ->and($paginator->items()[0]->log_name)->toBe('admin.machines');
    });

    it('filters by event', function (): void {
        makeEntry(['event' => 'created']);
        makeEntry(['event' => 'deleted']);

        expect($this->service->list(['event' => 'deleted'], 1, 50)->total())->toBe(1);
    });

    it('filters by subject_type FQN', function (): void {
        makeEntry(['subject_type' => Order::class, 'subject_id' => 5]);
        makeEntry(['subject_type' => Machine::class, 'subject_id' => 6]);

        $paginator = $this->service->list(['subject_type' => Order::class], 1, 50);

        expect($paginator->total())->toBe(1)
            ->and($paginator->items()[0]->subject_type)->toBe(Order::class);
    });

    it('resolves short alias `order` to App\\Models\\Order', function (): void {
        makeEntry(['subject_type' => Order::class]);
        makeEntry(['subject_type' => Machine::class]);

        $paginator = $this->service->list(['subject_type' => 'order'], 1, 50);

        expect($paginator->total())->toBe(1)
            ->and($paginator->items()[0]->subject_type)->toBe(Order::class);
    });

    it('resolves short alias `machine` to App\\Models\\Machine', function (): void {
        makeEntry(['subject_type' => Order::class]);
        makeEntry(['subject_type' => Machine::class]);

        $paginator = $this->service->list(['subject_type' => 'machine'], 1, 50);

        expect($paginator->total())->toBe(1)
            ->and($paginator->items()[0]->subject_type)->toBe(Machine::class);
    });

    it('passes through unknown subject_type strings (treated as raw FQN)', function (): void {
        makeEntry(['subject_type' => 'App\\Models\\NoSuchClass']);
        makeEntry(['subject_type' => Order::class]);

        $paginator = $this->service->list(['subject_type' => 'App\\Models\\NoSuchClass'], 1, 50);
        expect($paginator->total())->toBe(1);
    });

    it('filters by subject_id', function (): void {
        makeEntry(['subject_id' => 1]);
        makeEntry(['subject_id' => 42]);

        $paginator = $this->service->list(['subject_id' => 42], 1, 50);
        expect($paginator->total())->toBe(1);
    });

    it('filters by date_from + date_to (inclusive)', function (): void {
        makeEntry(['created_at' => now()->subDays(30)]);
        $kept = makeEntry(['created_at' => now()->subDay()]);

        $paginator = $this->service->list([
            'date_from' => now()->subDays(5)->toDateString(),
            'date_to' => now()->toDateString(),
        ], 1, 50);

        expect($paginator->total())->toBe(1)
            ->and($paginator->items()[0]->id)->toBe($kept->id);
    });

    it('filters by search across description (LIKE)', function (): void {
        makeEntry(['description' => 'order.cancelled']);
        makeEntry(['description' => 'machine.installed']);

        $paginator = $this->service->list(['search' => 'cancel'], 1, 50);
        expect($paginator->total())->toBe(1);
    });

    it('LEFT JOIN preserves causer fields for soft-deleted users', function (): void {
        $user = User::factory()->admin(UserRole::Operations->value)->create(['name' => 'Ex Ops']);
        makeEntry(['causer_id' => $user->id]);
        $user->delete();

        $row = $this->service->list(['actor_id' => $user->id], 1, 50)->items()[0];
        expect($row->causer_name)->toBe('Ex Ops')
            ->and($row->causer_role)->toBe(UserRole::Operations->value);
    });

    it('returns empty paginator when no rows match', function (): void {
        makeEntry();

        $paginator = $this->service->list(['log_name' => 'no.such.action'], 1, 50);
        expect($paginator->total())->toBe(0);
    });

    it('honours the page parameter', function (): void {
        for ($i = 0; $i < 5; $i++) {
            makeEntry();
        }

        $page1 = $this->service->list([], 1, 2);
        $page2 = $this->service->list([], 2, 2);

        expect($page1->total())->toBe(5)
            ->and(count($page1->items()))->toBe(2)
            ->and(count($page2->items()))->toBe(2)
            ->and($page1->items()[0]->id)->not->toBe($page2->items()[0]->id);
    });
});
