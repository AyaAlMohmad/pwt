<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seed the canonical public settings keys.
 *
 * Idempotent via `firstOrCreate`. Re-running keeps any operator
 * overrides intact — only missing keys are inserted. Private
 * keys are intentionally NOT seeded here; secrets live in `.env`
 * exclusively per CLAUDE.md § 16.
 */
final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $publicDefaults = [
            'supported_locales' => ['en', 'ar'],
            'default_locale' => 'en',
            'phone_number' => '+97141234567',
            'whatsapp_number' => '+971501234567',
            'contact_email' => 'info@pwtwater.com',
            'location' => [
                'address' => 'Dubai, United Arab Emirates',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
                'map_url' => 'https://maps.google.com/?q=25.2048,55.2708',
            ],
            'social_links' => [
                'facebook' => null,
                'instagram' => null,
                'linkedin' => null,
            ],
            'support_hours' => [
                'en' => 'Sun–Thu 9:00–18:00 GST',
                'ar' => 'الأحد–الخميس ٩:٠٠–١٨:٠٠ توقيت الخليج',
            ],
        ];

        foreach ($publicDefaults as $key => $value) {
            Setting::firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'is_public' => true],
            );
        }
    }
}
