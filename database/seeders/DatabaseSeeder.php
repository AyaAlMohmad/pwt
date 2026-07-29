<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the per-module seeders in dependency order:
 *   1. roles + permissions (production-safe)
 *   2. public settings    (production-safe)
 *   3. countries + regions (production-safe)
 *   4. catalog categories  (production-safe — Phase 6)
 *   5. catalog products    (production-safe — Phase 6; the 16 unit types)
 *   6. banners            (non-production only — Phase 7 demo data)
 *   7. promotions         (non-production only — Phase 7 demo data)
 *   8. testimonials       (non-production only — Phase 7 demo data)
 *   9. demo users         (non-production only — gated by the seeder itself)
 *
 * The catalog seeders run before `DemoUsersSeeder` so that any demo
 * data that wants to reference products (e.g. seeded orders later) can
 * resolve a product id; they also run before Phase 10's
 * `MachinesFromExcelSeeder`, which depends on `products.code`.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (
            app()->environment('production')
            && $this->command !== null
            && ! $this->command->confirm('Run seeders in production?', false)
        ) {
            $this->command->warn('[DatabaseSeeder] Aborted in production.');

            return;
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            SettingsSeeder::class,
            CountriesAndRegionsSeeder::class,
            CategoriesSeeder::class,
            ProductCatalogSeeder::class,
            // Phase 7 — demo banners / promotions / testimonials.
            // Each is env-gated to non-production by its own run() guard.
            BannersSeeder::class,
            PromotionsSeeder::class,
            TestimonialsSeeder::class,
            DemoUsersSeeder::class,
            // Phase 11 — notification template copy. Idempotent.
            NotificationTemplatesSeeder::class,
        ]);

        // Phase 10 — the 227-row Excel import. Non-fatal on failure so a
        // missing or malformed file does not block the rest of the
        // pipeline (CI bootstrapping commonly runs without the file).
        try {
            $this->call(MachinesFromExcelSeeder::class);
        } catch (Throwable $e) {
            Log::warning('[DatabaseSeeder] MachinesFromExcelSeeder failed — continuing.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
