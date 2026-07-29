<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MachineSource;
use App\Enums\ProductTerm;
use App\Models\Address;
use App\Models\Machine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seed demo machines for demo customers in non-production environments.
 */
final class DemoMachinesSeeder extends Seeder
{
    public function run(): void
    {
        $env = app()->environment();
        if (! in_array($env, ['local', 'development', 'testing'], true)) {
            $this->command?->warn(
                "[DemoMachinesSeeder] Skipped: refusing to seed demo machines in '$env' environment.",
            );

            return;
        }

        $user = User::query()->where('email', 'customer1@pwt.local')->first();
        if ($user === null) {
            $this->command?->warn('User customer1@pwt.local not found. Run DemoUsersSeeder first.');

            return;
        }

        $products = Product::query()->where('is_active', true)->limit(5)->get();
        if ($products->isEmpty()) {
            $this->command?->warn('No active products found. Run ProductCatalogSeeder first.');

            return;
        }

        $address = Address::query()->where('user_id', $user->id)->first();

        $this->seedPurchased($user, $products, $address);
        $this->seedRented($user, $products, $address);
        $this->seedWithMaintenance($user, $products, $address);

        $this->command?->info('Demo machines seeded for customer1@pwt.local.');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     */
    private function seedPurchased(User $user, $products, ?Address $address): void
    {
        foreach ($products->take(3) as $idx => $product) {
            Machine::firstOrCreate(
                [
                    'serial_number' => 'DEMO-PW-BUY-'.($idx + 1),
                    'user_id' => $user->id,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'address_id' => $address?->id ?? Address::factory()->forUser($user)->create()->id,
                    'product_id' => $product->id,
                    'term' => ProductTerm::Buy->value,
                    'installed_at' => now()->subMonths(rand(6, 24))->toDateString(),
                    'filter_change_interval_months' => 6,
                    'next_maintenance_due_at' => now()->addMonths(rand(1, 6))->toDateString(),
                    'location_label' => 'Site '.chr(65 + $idx),
                    'country_code' => 'ae',
                    'notes' => 'Demo purchased unit',
                    'is_active' => true,
                    'source' => MachineSource::Imported->value,
                    'order_id' => null,
                ]
            );
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     */
    private function seedRented(User $user, $products, ?Address $address): void
    {
        foreach ($products->take(3) as $idx => $product) {
            Machine::firstOrCreate(
                [
                    'serial_number' => 'DEMO-PW-RENT-'.($idx + 1),
                    'user_id' => $user->id,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'address_id' => $address?->id ?? Address::factory()->forUser($user)->create()->id,
                    'product_id' => $product->id,
                    'term' => ProductTerm::Rent->value,
                    'installed_at' => now()->subMonths(rand(3, 12))->toDateString(),
                    'filter_change_interval_months' => 6,
                    'next_maintenance_due_at' => now()->addMonths(rand(1, 4))->toDateString(),
                    'location_label' => 'Office '.chr(65 + $idx),
                    'country_code' => 'ae',
                    'notes' => 'Demo rented unit — monthly billing',
                    'is_active' => true,
                    'source' => MachineSource::Imported->value,
                    'order_id' => null,
                ]
            );
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     */
    private function seedWithMaintenance(User $user, $products, ?Address $address): void
    {
        $product = $products->first();
        if ($product === null) {
            return;
        }

        Machine::firstOrCreate(
            [
                'serial_number' => 'DEMO-PW-MAINT-1',
                'user_id' => $user->id,
            ],
            [
                'public_id' => (string) Str::uuid(),
                'address_id' => $address?->id ?? Address::factory()->forUser($user)->create()->id,
                'product_id' => $product->id,
                'term' => ProductTerm::Rent->value,
                'installed_at' => now()->subMonths(18)->toDateString(),
                'filter_change_interval_months' => 6,
                'next_maintenance_due_at' => now()->addDays(3)->toDateString(),
                'location_label' => 'Headquarters',
                'country_code' => 'ae',
                'notes' => 'Demo unit — maintenance due soon',
                'is_active' => true,
                'source' => MachineSource::Imported->value,
                'order_id' => null,
            ]
        );
    }
}
