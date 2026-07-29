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
        $superAdmin = Role::findByName('super_admin', self::GUARD);
        if ($superAdmin !== null) {
            $superAdmin->givePermissionTo([$view, $update]);
        }

        // Assign view to operations and support (mirrors maintenance.view pattern)
        $operations = Role::findByName('operations', self::GUARD);
        if ($operations !== null) {
            $operations->givePermissionTo($view);
        }

        $support = Role::findByName('support', self::GUARD);
        if ($support !== null) {
            $support->givePermissionTo($view);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findByName('rfq.view', self::GUARD)?->delete();
        Permission::findByName('rfq.update', self::GUARD)?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
