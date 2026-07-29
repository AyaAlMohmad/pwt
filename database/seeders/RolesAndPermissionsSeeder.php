<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seed the roles + permissions catalogue.
 *
 * Idempotent: each role and permission is created with
 * `firstOrCreate`, and role-to-permission assignments use
 * `syncPermissions` so re-running the seeder never duplicates
 * pivot rows.
 *
 * Roles map directly to the {@see UserRole} enum so callers can
 * reference role names through the enum throughout the codebase.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        // Reset the spatie permission cache before mutating.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = $this->permissionKeys();

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }

        $superAdmin = Role::findOrCreate(UserRole::SuperAdmin->value, self::GUARD);
        $operations = Role::findOrCreate(UserRole::Operations->value, self::GUARD);
        $sales = Role::findOrCreate(UserRole::Sales->value, self::GUARD);
        $support = Role::findOrCreate(UserRole::Support->value, self::GUARD);
        $customer = Role::findOrCreate(UserRole::Customer->value, self::GUARD);

        $superAdmin->syncPermissions(Permission::all());

        // operations also gets read-only catalog access for the dashboard
        // workflow widgets (Phase 16 — admin-catalog.md § 6.4 / § 9.1) and
        // the full Phase 21 reports bucket (admin-reports.md § 6.4 / § 6.6).
        $operations->syncPermissions(array_merge(
            $this->keysWithPrefix($permissions, 'orders.'),
            $this->keysWithPrefix($permissions, 'maintenance.'),
            $this->keysWithPrefix($permissions, 'machines.'),
            $this->keysWithPrefix($permissions, 'rfq.'),
            ['support.view', 'categories.view', 'products.view'],
            ['reports.view', 'reports.export'],
        ));

        // sales keeps the full catalog prefix; the destructive routes are
        // additionally policy-gated to super_admin (CategoryPolicy::delete,
        // ProductPolicy::delete) per admin-catalog.md § 9.1. Phase 21
        // grants the full reports bucket (admin-reports.md § 6.4 / § 6.6).
        $sales->syncPermissions(array_merge(
            $this->keysWithPrefix($permissions, 'products.'),
            $this->keysWithPrefix($permissions, 'categories.'),
            $this->keysWithPrefix($permissions, 'promotions.'),
            $this->keysWithPrefix($permissions, 'banners.'),
            $this->keysWithPrefix($permissions, 'testimonials.'),
            ['reports.view', 'reports.export'],
        ));

        // support also gets read-only catalog access for ticket context
        // and the scoped Phase 21 reports access (maintenance + customers
        // only — narrowed by ReportPolicy, not by permission keys).
        $support->syncPermissions(array_merge(
            $this->keysWithPrefix($permissions, 'support.'),
            ['orders.view', 'maintenance.view', 'rfq.view', 'users.view', 'categories.view', 'products.view'],
            ['reports.view', 'reports.export'],
        ));

        // Customer role exists for assignment to end users; customer
        // capability is enforced by Sanctum + ownership policies, not
        // by admin permission keys.
        $customer->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Flat list of every admin permission key.
     *
     * @return list<string>
     */
    private function permissionKeys(): array
    {
        return [
            // Users
            'users.view', 'users.create', 'users.update', 'users.deactivate',

            // Products
            'products.view', 'products.create', 'products.update', 'products.deactivate', 'products.manage-images',

            // Categories
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',

            // Orders. Customer-side access is policy-driven (ownership
            // checks in OrderPolicy), so customer order creation /
            // cancellation does NOT need a permission key. The keys
            // below are admin-side; Phase 17 wires admin endpoints
            // against them. `orders.view` remains as the generic admin
            // read permission (already used by the `support` role).
            //
            // Reserved admin-side keys:
            //   - `orders.view`         — read any customer order (existing)
            //   - `orders.update-status` — drive the admin state machine (existing)
            //   - `orders.cancel`       — admin cancellation override (existing)
            //   - `orders.schedule`     — admin schedule install date (existing)
            //   - `orders.cancel-any`   — admin force-cancel beyond customer rules
            //   - `orders.confirm`      — admin manual COD confirm (Phase 17)
            //   - `orders.refund`       — admin manual refund (Phase 2)
            'orders.view', 'orders.update-status', 'orders.cancel', 'orders.schedule',
            'orders.cancel-any', 'orders.confirm', 'orders.refund',

            // Maintenance
            'maintenance.view', 'maintenance.create', 'maintenance.assign', 'maintenance.update', 'maintenance.close',

            // Machines
            'machines.view', 'machines.create', 'machines.update',

            // Support
            'support.view', 'support.reply', 'support.update', 'support.create', 'support.cancel',

            // Promotions
            'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',

            // Banners
            'banners.view', 'banners.create', 'banners.update', 'banners.delete',

            // Testimonials
            'testimonials.view', 'testimonials.create', 'testimonials.update', 'testimonials.delete',

            // Reports
            'reports.view', 'reports.export',

            // Audit logs
            'audit_logs.view',

            // Notifications
            'notifications.view', 'notifications.templates',

            // RFQ
            'rfq.view', 'rfq.update',
        ];
    }

    /**
     * Filter the catalogue down to a single domain prefix.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function keysWithPrefix(array $keys, string $prefix): array
    {
        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));
    }
}
