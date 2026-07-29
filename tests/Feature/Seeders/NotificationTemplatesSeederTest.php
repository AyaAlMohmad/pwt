<?php

declare(strict_types=1);

use App\Models\NotificationTemplate;
use Database\Seeders\NotificationTemplatesSeeder;

/*
|--------------------------------------------------------------------------
| NotificationTemplatesSeeder integration test
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 10.3.
| Seeds one row per (event-key × channel) pair from
| config('notifications.channels_per_type'). Idempotent via updateOrCreate.
| Bilingual subject + body. Run on the MySQL pipeline because the
| translatable JSON column needs proper JSON support.
*/

describe('NotificationTemplatesSeeder', function (): void {
    it('creates exactly one row per (key, channel) pair listed in config', function (): void {
        $this->seed(NotificationTemplatesSeeder::class);

        /** @var array<string, list<string>> $channelsPerType */
        $channelsPerType = (array) config('notifications.channels_per_type', []);
        $expected = 0;
        foreach ($channelsPerType as $channels) {
            $expected += count($channels);
        }

        // Sanity guard — we expect at least 20+ rows; the spec calls out ~23.
        expect($expected)->toBeGreaterThanOrEqual(20);

        expect(NotificationTemplate::query()->count())->toBe($expected);

        // Every configured (key, channel) pair must exist.
        foreach ($channelsPerType as $key => $channels) {
            foreach ($channels as $channelValue) {
                $row = NotificationTemplate::query()
                    ->where('key', $key)
                    ->where('channel', $channelValue)
                    ->first();
                expect($row)->not->toBeNull("Missing template for key={$key}, channel={$channelValue}");
            }
        }
    });

    it('seeds bilingual subject + body for every row', function (): void {
        $this->seed(NotificationTemplatesSeeder::class);

        $rows = NotificationTemplate::query()->get();
        foreach ($rows as $row) {
            $subjectEn = (string) $row->getTranslation('subject', 'en');
            $subjectAr = (string) $row->getTranslation('subject', 'ar');
            $bodyEn = (string) $row->getTranslation('body', 'en');
            $bodyAr = (string) $row->getTranslation('body', 'ar');

            expect($subjectEn)->not->toBe('', "key={$row->key} channel={$row->channel->value} missing EN subject");
            expect($subjectAr)->not->toBe('', "key={$row->key} channel={$row->channel->value} missing AR subject");
            expect($bodyEn)->not->toBe('', "key={$row->key} channel={$row->channel->value} missing EN body");
            expect($bodyAr)->not->toBe('', "key={$row->key} channel={$row->channel->value} missing AR body");
        }
    });

    it('is idempotent — running the seeder twice does not create duplicate rows', function (): void {
        $this->seed(NotificationTemplatesSeeder::class);
        $firstCount = NotificationTemplate::query()->count();

        $this->seed(NotificationTemplatesSeeder::class);
        $secondCount = NotificationTemplate::query()->count();

        expect($secondCount)->toBe($firstCount);
    });

    it('marks every seeded row as active', function (): void {
        $this->seed(NotificationTemplatesSeeder::class);

        $inactive = NotificationTemplate::query()->where('is_active', false)->count();
        expect($inactive)->toBe(0);
    });

    it('records the placeholders array for each row', function (): void {
        $this->seed(NotificationTemplatesSeeder::class);

        $rows = NotificationTemplate::query()->get();
        foreach ($rows as $row) {
            expect($row->placeholders)->toBeArray();
            expect(count((array) $row->placeholders))->toBeGreaterThan(0);
        }
    });
});
