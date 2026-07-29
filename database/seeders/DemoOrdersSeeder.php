<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seed demo orders for customer1@pwt.local in non-production environments.
 */
final class DemoOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $env = app()->environment();
        if (! in_array($env, ['local', 'development', 'testing'], true)) {
            $this->command?->warn(
                "[DemoOrdersSeeder] Skipped: refusing to seed demo orders in '$env' environment.",
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

        $addresses = Address::query()->where('user_id', $user->id)->limit(4)->get();
        if ($addresses->isEmpty()) {
            $addresses = collect([Address::factory()->forUser($user)->create()]);
        }

        $this->seedPendingOrders($user, $products, $addresses);
        $this->seedConfirmedOrders($user, $products, $addresses);
        $this->seedScheduledOrders($user, $products, $addresses);
        $this->seedCompletedOrders($user, $products, $addresses);
        $this->seedCancelledOrders($user, $products, $addresses);

        $this->command?->info('Demo orders seeded for customer1@pwt.local.');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     * @param  \Illuminate\Database\Eloquent\Collection<int, Address>  $addresses
     */
    private function seedPendingOrders(User $user, $products, $addresses): void
    {
        foreach (range(1, 3) as $i) {
            $order = Order::factory()
                ->forUser($user)
                ->forAddress($addresses->random())
                ->pending()
                ->state([
                    'currency' => 'AED',
                    'subtotal_amount' => '10000.00',
                    'total_amount' => '10500.00',
                    'tax_amount' => '500.00',
                ])
                ->create();

            OrderItem::factory()
                ->forOrder($order)
                ->forProduct($products->random())
                ->withUnitPrice(5000.00, 2, 'AED')
                ->create();
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     * @param  \Illuminate\Database\Eloquent\Collection<int, Address>  $addresses
     */
    private function seedConfirmedOrders(User $user, $products, $addresses): void
    {
        foreach (range(1, 2) as $i) {
            $order = Order::factory()
                ->forUser($user)
                ->forAddress($addresses->random())
                ->confirmed()
                ->state([
                    'currency' => 'AED',
                    'subtotal_amount' => '15000.00',
                    'total_amount' => '15750.00',
                    'tax_amount' => '750.00',
                ])
                ->create();

            OrderItem::factory()
                ->forOrder($order)
                ->forProduct($products->random())
                ->withUnitPrice(7500.00, 2, 'AED')
                ->create();
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     * @param  \Illuminate\Database\Eloquent\Collection<int, Address>  $addresses
     */
    private function seedScheduledOrders(User $user, $products, $addresses): void
    {
        $order = Order::factory()
            ->forUser($user)
            ->forAddress($addresses->random())
            ->state([
                'status' => 'scheduled',
                'currency' => 'AED',
                'subtotal_amount' => '20000.00',
                'total_amount' => '21000.00',
                'tax_amount' => '1000.00',
                'scheduled_for_at' => now()->addDays(3),
            ])
            ->create();

        OrderItem::factory()
            ->forOrder($order)
            ->forProduct($products->random())
            ->withUnitPrice(5000.00, 4, 'AED')
            ->create();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     * @param  \Illuminate\Database\Eloquent\Collection<int, Address>  $addresses
     */
    private function seedCompletedOrders(User $user, $products, $addresses): void
    {
        foreach (range(1, 2) as $i) {
            $order = Order::factory()
                ->forUser($user)
                ->forAddress($addresses->random())
                ->completed()
                ->state([
                    'currency' => 'AED',
                    'subtotal_amount' => '8000.00',
                    'total_amount' => '8400.00',
                    'tax_amount' => '400.00',
                ])
                ->create();

            OrderItem::factory()
                ->forOrder($order)
                ->forProduct($products->random())
                ->withUnitPrice(4000.00, 2, 'AED')
                ->create();
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Product>  $products
     * @param  \Illuminate\Database\Eloquent\Collection<int, Address>  $addresses
     */
    private function seedCancelledOrders(User $user, $products, $addresses): void
    {
        $order = Order::factory()
            ->forUser($user)
            ->forAddress($addresses->random())
            ->cancelled()
            ->state([
                'currency' => 'AED',
                'subtotal_amount' => '5000.00',
                'total_amount' => '5250.00',
                'tax_amount' => '250.00',
            ])
            ->create();

        OrderItem::factory()
            ->forOrder($order)
            ->forProduct($products->random())
            ->withUnitPrice(2500.00, 2, 'AED')
            ->create();
    }
}
