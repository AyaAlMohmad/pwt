<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Country;
use App\Models\Region;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| RolesAndPermissionsSeeder
|--------------------------------------------------------------------------
*/

describe('RolesAndPermissionsSeeder', function (): void {
    beforeEach(function (): void {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
    });

    it('creates exactly 5 roles matching the UserRole enum', function (): void {
        $roleNames = Role::query()->pluck('name')->all();

        expect($roleNames)->toEqualCanonicalizing([
            UserRole::SuperAdmin->value,
            UserRole::Operations->value,
            UserRole::Sales->value,
            UserRole::Support->value,
            UserRole::Customer->value,
        ]);

        expect(Role::query()->count())->toBe(5);
    });

    it('grants the super_admin role every permission (50 in total)', function (): void {
        $superAdmin = Role::query()->where('name', UserRole::SuperAdmin->value)->firstOrFail();
        $totalPermissions = Permission::query()->count();

        // Phase 8 added 3 admin-side reservations: orders.cancel-any,
        // orders.confirm, orders.refund (45 → 48).
        // Phase 12 added 2 customer-facing keys: support.create, support.cancel (48 → 50).
        expect($totalPermissions)->toBe(50);
        expect($superAdmin->permissions()->count())->toBe($totalPermissions);
    });

    it('grants the customer role zero admin permissions', function (): void {
        $customer = Role::query()->where('name', UserRole::Customer->value)->firstOrFail();

        expect($customer->permissions()->count())->toBe(0);
    });

    it('grants operations orders/maintenance/machines plus support.view + read-only catalog + reports', function (): void {
        $operations = Role::query()->where('name', UserRole::Operations->value)->firstOrFail();
        $permissions = $operations->permissions()->pluck('name')->all();

        foreach ($permissions as $perm) {
            expect(
                str_starts_with($perm, 'orders.')
                || str_starts_with($perm, 'maintenance.')
                || str_starts_with($perm, 'machines.')
                || $perm === 'support.view'
                || $perm === 'categories.view'
                || $perm === 'products.view'
                || $perm === 'reports.view'
                || $perm === 'reports.export',
            )->toBeTrue("operations should not have '{$perm}'");
        }

        // Phase 16 — operations gets read-only catalog access.
        expect($permissions)->toContain('categories.view');
        expect($permissions)->toContain('products.view');

        // Phase 21 — operations gets the full reports bucket.
        expect($permissions)->toContain('reports.view');
        expect($permissions)->toContain('reports.export');

        // Should not have product/category WRITE perms.
        expect($permissions)->not->toContain('products.create');
        expect($permissions)->not->toContain('products.update');
        expect($permissions)->not->toContain('products.deactivate');
        expect($permissions)->not->toContain('categories.create');
        expect($permissions)->not->toContain('categories.update');
        expect($permissions)->not->toContain('categories.delete');
    });

    it('grants sales the catalog + promotions + banners + testimonials + reports buckets', function (): void {
        $sales = Role::query()->where('name', UserRole::Sales->value)->firstOrFail();
        $permissions = $sales->permissions()->pluck('name')->all();

        expect($permissions)->toContain('products.view');
        expect($permissions)->toContain('products.create');
        expect($permissions)->toContain('promotions.create');
        expect($permissions)->toContain('banners.update');
        expect($permissions)->toContain('testimonials.delete');

        // Phase 21 — sales gets the full reports bucket.
        expect($permissions)->toContain('reports.view');
        expect($permissions)->toContain('reports.export');

        // Should not have order or maintenance management.
        expect($permissions)->not->toContain('orders.cancel');
        expect($permissions)->not->toContain('maintenance.assign');
    });

    it('grants support the support bucket plus read-only views + scoped reports', function (): void {
        $support = Role::query()->where('name', UserRole::Support->value)->firstOrFail();
        $permissions = $support->permissions()->pluck('name')->all();

        expect($permissions)->toContain('support.view');
        expect($permissions)->toContain('support.reply');
        expect($permissions)->toContain('support.update');
        expect($permissions)->toContain('orders.view');
        expect($permissions)->toContain('maintenance.view');
        expect($permissions)->toContain('users.view');

        // Phase 16 — support gets read-only catalog access for ticket context.
        expect($permissions)->toContain('categories.view');
        expect($permissions)->toContain('products.view');

        // Phase 21 — support gets the reports permission keys (per-type
        // scoping is enforced by ReportPolicy, not the permission keys).
        expect($permissions)->toContain('reports.view');
        expect($permissions)->toContain('reports.export');

        // Should not have write powers on orders or products.
        expect($permissions)->not->toContain('orders.update-status');
        expect($permissions)->not->toContain('products.update');
        expect($permissions)->not->toContain('products.deactivate');
        expect($permissions)->not->toContain('categories.delete');
    });

    it('is idempotent — running twice does not duplicate roles or permissions', function (): void {
        $rolesBefore = Role::query()->count();
        $permissionsBefore = Permission::query()->count();

        $this->seed(RolesAndPermissionsSeeder::class);

        expect(Role::query()->count())->toBe($rolesBefore);
        expect(Permission::query()->count())->toBe($permissionsBefore);

        $superAdmin = Role::query()->where('name', UserRole::SuperAdmin->value)->firstOrFail();
        expect($superAdmin->permissions()->count())->toBe($permissionsBefore);
    });
});

/*
|--------------------------------------------------------------------------
| CountriesAndRegionsSeeder
|--------------------------------------------------------------------------
*/

describe('CountriesAndRegionsSeeder', function (): void {
    beforeEach(function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);
    });

    it('seeds exactly 6 countries with the GCC codes', function (): void {
        expect(Country::query()->count())->toBe(6);

        $codes = Country::query()->orderBy('code')->pluck('code')->all();
        expect($codes)->toEqualCanonicalizing(['ae', 'bh', 'kw', 'om', 'qa', 'sa']);
    });

    it('seeds the expected number of regions per country', function (): void {
        $expected = [
            'ae' => 7,
            'sa' => 13,
            'qa' => 8,
            'kw' => 6,
            'bh' => 4,
            'om' => 11,
        ];

        foreach ($expected as $code => $count) {
            $country = Country::query()->where('code', $code)->firstOrFail();
            $actual = Region::query()->where('country_id', $country->id)->count();
            expect($actual)->toBe($count, "Country {$code} should have {$count} regions, got {$actual}");
        }
    });

    it('seeds bilingual (en + ar) names for every country', function (): void {
        $countries = Country::query()->get();

        foreach ($countries as $country) {
            $translations = $country->getTranslations('name');
            expect($translations)->toHaveKey('en');
            expect($translations)->toHaveKey('ar');
            expect($translations['en'])->toBeString()->not->toBeEmpty();
            expect($translations['ar'])->toBeString()->not->toBeEmpty();
        }
    });

    it('seeds bilingual (en + ar) names for every region', function (): void {
        $regions = Region::query()->get();

        expect($regions)->not->toBeEmpty();
        foreach ($regions as $region) {
            $translations = $region->getTranslations('name');
            expect($translations)->toHaveKey('en');
            expect($translations)->toHaveKey('ar');
            expect($translations['en'])->toBeString()->not->toBeEmpty();
            expect($translations['ar'])->toBeString()->not->toBeEmpty();
        }
    });

    it('seeds the documented VAT rates per country', function (): void {
        $expected = [
            'ae' => 5.0,
            'sa' => 15.0,
            'qa' => 0.0,
            'kw' => 0.0,
            'bh' => 10.0,
            'om' => 5.0,
        ];

        foreach ($expected as $code => $rate) {
            $country = Country::query()->where('code', $code)->firstOrFail();
            expect((float) $country->vat_rate)->toBe($rate, "Country {$code} VAT");
        }
    });

    it('seeds the documented currencies per country', function (): void {
        $expected = [
            'ae' => 'AED',
            'sa' => 'SAR',
            'qa' => 'QAR',
            'kw' => 'KWD',
            'bh' => 'BHD',
            'om' => 'OMR',
        ];

        foreach ($expected as $code => $currency) {
            $country = Country::query()->where('code', $code)->firstOrFail();
            expect($country->currency)->toBe($currency);
        }
    });

    it('marks every seeded country as active', function (): void {
        $inactive = Country::query()->where('is_active', false)->count();
        expect($inactive)->toBe(0);
    });

    it('is idempotent — re-running does not duplicate rows', function (): void {
        $countriesBefore = Country::query()->count();
        $regionsBefore = Region::query()->count();

        $this->seed(CountriesAndRegionsSeeder::class);

        expect(Country::query()->count())->toBe($countriesBefore);
        expect(Region::query()->count())->toBe($regionsBefore);
    });
});

/*
|--------------------------------------------------------------------------
| SettingsSeeder
|--------------------------------------------------------------------------
*/

describe('SettingsSeeder', function (): void {
    beforeEach(function (): void {
        $this->seed(SettingsSeeder::class);
    });

    it('seeds the public settings keys', function (): void {
        $expected = [
            'supported_locales',
            'default_locale',
            'whatsapp_number',
            'contact_email',
            'social_links',
            'support_hours',
        ];

        foreach ($expected as $key) {
            expect(
                Setting::query()->where('key', $key)->where('is_public', true)->exists(),
            )->toBeTrue("setting '{$key}' should exist as is_public=true");
        }
    });

    it('is idempotent — re-running does not duplicate rows', function (): void {
        $countBefore = Setting::query()->count();

        $this->seed(SettingsSeeder::class);

        expect(Setting::query()->count())->toBe($countBefore);
    });
});

/*
|--------------------------------------------------------------------------
| DemoUsersSeeder
|--------------------------------------------------------------------------
*/

describe('DemoUsersSeeder', function (): void {
    beforeEach(function (): void {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Roles must exist before users can be assigned to them.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(DemoUsersSeeder::class);
    });

    it('seeds 9 demo users (1 super admin + 3 admins + 5 customers)', function (): void {
        expect(User::query()->count())->toBe(9);
    });

    it('seeds the super admin with role super_admin', function (): void {
        $user = User::query()->where('email', 'admin@pwt.local')->firstOrFail();

        expect($user->hasRole(UserRole::SuperAdmin->value))->toBeTrue();
        expect($user->name)->toBe('PWT Super Admin');
    });

    it('seeds ops, sales, and support leads with the correct roles', function (): void {
        $expectations = [
            'ops@pwt.local' => UserRole::Operations,
            'sales@pwt.local' => UserRole::Sales,
            'support@pwt.local' => UserRole::Support,
        ];

        foreach ($expectations as $email => $role) {
            $user = User::query()->where('email', $email)->firstOrFail();
            expect($user->hasRole($role->value))->toBeTrue("User {$email} should have role {$role->value}");
        }
    });

    it('seeds 5 demo customers', function (): void {
        $customers = User::query()->role(UserRole::Customer->value)->get();
        expect($customers)->toHaveCount(5);
    });

    it('hashes demo user passwords (never stores plaintext)', function (): void {
        $user = User::query()->where('email', 'admin@pwt.local')->firstOrFail();

        // Password column must not contain the raw secret.
        expect($user->password)->not->toBe('Password123!');
        // But bcrypt::check must accept it.
        expect(Hash::check('Password123!', $user->password))->toBeTrue();
    });

    it('is idempotent — re-running does not duplicate users', function (): void {
        $countBefore = User::query()->count();

        $this->seed(DemoUsersSeeder::class);

        expect(User::query()->count())->toBe($countBefore);

        // Role assignments are still single (no duplicates).
        $superAdmin = User::query()->where('email', 'admin@pwt.local')->firstOrFail();
        expect($superAdmin->roles()->count())->toBe(1);
    });
});
