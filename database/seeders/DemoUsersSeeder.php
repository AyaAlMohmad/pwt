<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed demo users for non-production environments only.
 *
 * Refuses to run in production — production credentials must be
 * provisioned out-of-band. Idempotent via `firstOrCreate` keyed on
 * email; running the seeder twice never duplicates accounts.
 *
 * Default password for every demo account: `Password123!`. This is
 * documented openly because these users do not exist in production.
 */
final class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $env = app()->environment();
        if (! in_array($env, ['local', 'development', 'testing'], true)) {
            $this->command?->warn(
                "[DemoUsersSeeder] Skipped: refusing to seed demo users in '$env' environment.",
            );

            return;
        }

        foreach ($this->users() as $row) {
            /** @var array{email: string, name: string, phone: string, role: UserRole} $row */
            $user = User::firstOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'phone_country_code' => '+971',
                    'phone' => $row['phone'],
                    'locale' => 'en',
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'password' => Hash::make('Password123!'),
                ],
            );

            if (! $user->hasRole($row['role']->value)) {
                $user->assignRole($row['role']->value);
            }
        }
    }

    /**
     * @return list<array{email: string, name: string, phone: string, role: UserRole}>
     */
    private function users(): array
    {
        return [
            ['email' => 'admin@pwt.local', 'name' => 'PWT Super Admin', 'phone' => '500000001', 'role' => UserRole::SuperAdmin],
            ['email' => 'ops@pwt.local', 'name' => 'Operations Lead', 'phone' => '500000002', 'role' => UserRole::Operations],
            ['email' => 'sales@pwt.local', 'name' => 'Sales Lead', 'phone' => '500000003', 'role' => UserRole::Sales],
            ['email' => 'support@pwt.local', 'name' => 'Support Lead', 'phone' => '500000004', 'role' => UserRole::Support],
            ['email' => 'customer1@pwt.local', 'name' => 'Demo Customer 1', 'phone' => '500000006', 'role' => UserRole::Customer],
            ['email' => 'customer2@pwt.local', 'name' => 'Demo Customer 2', 'phone' => '500000007', 'role' => UserRole::Customer],
            ['email' => 'customer3@pwt.local', 'name' => 'Demo Customer 3', 'phone' => '500000008', 'role' => UserRole::Customer],
            ['email' => 'customer4@pwt.local', 'name' => 'Demo Customer 4', 'phone' => '500000009', 'role' => UserRole::Customer],
            ['email' => 'customer5@pwt.local', 'name' => 'Demo Customer 5', 'phone' => '500000010', 'role' => UserRole::Customer],
        ];
    }
}
