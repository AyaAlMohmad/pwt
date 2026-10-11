<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GUARD = 'web';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $view = Permission::findOrCreate('rfq.view', self::GUARD);
        $update = Permission::findOrCreate('rfq.update', self::GUARD);

        // Assign to super_admin if it exists
        $superAdmin = $this->findRole('super_admin');
        if ($superAdmin !== null) {
            $superAdmin->givePermissionTo([$view, $update]);
        }

        // Assign view to operations and support (mirrors maintenance.view pattern)
        $operations = $this->findRole('operations');
        if ($operations !== null) {
            $operations->givePermissionTo($view);
        }

        $support = $this->findRole('support');
        if ($support !== null) {
            $support->givePermissionTo($view);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', ['rfq.view', 'rfq.update'])
            ->where('guard_name', self::GUARD)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Resolve a role without throwing when it does not exist yet — on a
     * fresh database the migrations run before RolesAndPermissionsSeeder.
     */
    private function findRole(string $name): ?Role
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->first();
    }
};
