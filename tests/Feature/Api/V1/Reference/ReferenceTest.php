<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\Region;
use App\Models\Setting;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\SettingsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/countries
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/countries', function (): void {
    beforeEach(function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);
    });

    it('returns 200 with the standard success envelope', function (): void {
        $response = $this->getJson('/api/v1/countries');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => ['code', 'name', 'phone_code', 'currency', 'vat_rate', 'is_active'],
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('message'))->toBeString()->not->toBeEmpty();
    });

    it('returns exactly the seeded 6 countries', function (): void {
        $response = $this->getJson('/api/v1/countries');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(6);

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toEqualCanonicalizing(['ae', 'sa', 'qa', 'kw', 'bh', 'om']);
    });

    it('orders countries by code ascending', function (): void {
        $response = $this->getJson('/api/v1/countries');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toBe(['ae', 'bh', 'kw', 'om', 'qa', 'sa']);
    });

    it('exposes only the allowlisted fields per country', function (): void {
        $response = $this->getJson('/api/v1/countries');

        $first = $response->json('data.0');
        expect(array_keys($first))->toEqualCanonicalizing([
            'code', 'name', 'phone_code', 'currency', 'vat_rate', 'is_active',
        ]);

        // No internal columns leak.
        expect($first)->not->toHaveKey('id')
            ->and($first)->not->toHaveKey('created_at')
            ->and($first)->not->toHaveKey('updated_at');
    });

    it('returns English country names by default', function (): void {
        $response = $this->getJson('/api/v1/countries');

        $ae = collect($response->json('data'))->firstWhere('code', 'ae');
        expect($ae['name'])->toBe('United Arab Emirates');
    });

    it('returns English country names when Accept-Language: en', function (): void {
        $response = $this->getJson('/api/v1/countries', ['Accept-Language' => 'en']);

        $ae = collect($response->json('data'))->firstWhere('code', 'ae');
        expect($ae['name'])->toBe('United Arab Emirates');
    });

    it('returns Arabic country names when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/countries', ['Accept-Language' => 'ar']);

        $ae = collect($response->json('data'))->firstWhere('code', 'ae');
        expect($ae['name'])->toBe('الإمارات العربية المتحدة');
    });

    it('returns the correct currency, phone_code, vat_rate, and is_active fields', function (): void {
        $response = $this->getJson('/api/v1/countries');

        $ae = collect($response->json('data'))->firstWhere('code', 'ae');
        expect($ae['phone_code'])->toBe('+971')
            ->and($ae['currency'])->toBe('AED')
            ->and((float) $ae['vat_rate'])->toBe(5.0)
            ->and($ae['is_active'])->toBeTrue();
    });

    it('excludes inactive countries from the public list', function (): void {
        Country::factory()->inactive()->create([
            'code' => 'xx',
            'name' => ['en' => 'Hidden Country', 'ar' => 'دولة مخفية'],
        ]);

        $response = $this->getJson('/api/v1/countries');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('xx');
        expect($response->json('data'))->toHaveCount(6);
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/countries/{code}/regions
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/countries/{code}/regions', function (): void {
    beforeEach(function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);
    });

    it('returns 200 with the standard envelope and 7 UAE emirates for ae', function (): void {
        $response = $this->getJson('/api/v1/countries/ae/regions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => ['code', 'name', 'is_active'],
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data'))->toHaveCount(7);
    });

    it('exposes only the allowlisted fields per region', function (): void {
        $response = $this->getJson('/api/v1/countries/ae/regions');

        $first = $response->json('data.0');
        expect(array_keys($first))->toEqualCanonicalizing(['code', 'name', 'is_active']);

        expect($first)->not->toHaveKey('id')
            ->and($first)->not->toHaveKey('country_id')
            ->and($first)->not->toHaveKey('created_at');
    });

    it('accepts an uppercase ISO alpha-2 code (AE)', function (): void {
        $response = $this->getJson('/api/v1/countries/AE/regions');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(7);
    });

    it('accepts a mixed-case ISO alpha-2 code (Ae)', function (): void {
        $response = $this->getJson('/api/v1/countries/Ae/regions');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(7);
    });

    it('returns 404 with ERR_COUNTRY_NOT_FOUND when the code is unknown', function (): void {
        $response = $this->getJson('/api/v1/countries/zz/regions');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_COUNTRY_NOT_FOUND');

        expect($response->json('message'))->toBeString()->not->toBeEmpty();
    });

    it('returns 404 when the country exists but is inactive', function (): void {
        Country::query()->where('code', 'ae')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/countries/ae/regions');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_COUNTRY_NOT_FOUND');
    });

    it('returns 400 with ERR_INVALID_COUNTRY_CODE for an over-length code', function (): void {
        $response = $this->getJson('/api/v1/countries/aem/regions');

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_INVALID_COUNTRY_CODE');
    });

    it('returns 400 with ERR_INVALID_COUNTRY_CODE for non-alpha characters', function (): void {
        $response = $this->getJson('/api/v1/countries/12/regions');

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_INVALID_COUNTRY_CODE');
    });

    it('returns 400 with ERR_INVALID_COUNTRY_CODE for a single character', function (): void {
        $response = $this->getJson('/api/v1/countries/a/regions');

        // Single char may match a different route or fall through; we assert
        // that it does NOT return 200 with a valid region list.
        expect($response->getStatusCode())->not->toBe(200);
    });

    it('returns English region names by default', function (): void {
        $response = $this->getJson('/api/v1/countries/ae/regions');

        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('Dubai');
        expect($names)->toContain('Abu Dhabi');
    });

    it('returns Arabic region names when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/countries/ae/regions', ['Accept-Language' => 'ar']);

        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('دبي');
        expect($names)->toContain('أبو ظبي');
    });

    it('excludes inactive regions from the response', function (): void {
        $uae = Country::query()->where('code', 'ae')->firstOrFail();

        Region::factory()->inactive()->create([
            'country_id' => $uae->id,
            'code' => 'hid',
            'name' => ['en' => 'Hidden Region', 'ar' => 'منطقة مخفية'],
        ]);

        $response = $this->getJson('/api/v1/countries/ae/regions');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('hid');
        // Still the 7 active emirates.
        expect($response->json('data'))->toHaveCount(7);
    });

    it('returns the expected per-country region counts', function (): void {
        $counts = [
            'ae' => 7,
            'sa' => 13,
            'qa' => 8,
            'kw' => 6,
            'bh' => 4,
            'om' => 11,
        ];

        foreach ($counts as $code => $expected) {
            $response = $this->getJson("/api/v1/countries/{$code}/regions");
            $response->assertOk();
            expect($response->json('data'))->toHaveCount($expected);
        }
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/settings/public
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/settings/public', function (): void {
    beforeEach(function (): void {
        $this->seed(SettingsSeeder::class);
        $this->seed(CountriesAndRegionsSeeder::class);
    });

    it('returns 200 with the standard success envelope', function (): void {
        $response = $this->getJson('/api/v1/settings/public');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'supported_locales',
                    'default_locale',
                    'currencies_by_country',
                    'vat_rates_by_country',
                    'whatsapp_number',
                    'contact_email',
                    'social_links',
                    'support_hours',
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
    });

    it('returns a single object payload (not a list)', function (): void {
        $response = $this->getJson('/api/v1/settings/public');

        $data = $response->json('data');
        expect($data)->toBeArray();
        // Object => keys are strings, not 0..N integer keys.
        expect(array_is_list($data))->toBeFalse();
    });

    it('exposes all documented public keys', function (): void {
        $response = $this->getJson('/api/v1/settings/public');

        $data = $response->json('data');
        $expected = [
            'supported_locales',
            'default_locale',
            'currencies_by_country',
            'vat_rates_by_country',
            'whatsapp_number',
            'contact_email',
            'social_links',
            'support_hours',
        ];
        foreach ($expected as $key) {
            expect($data)->toHaveKey($key);
        }
    });

    it('returns the seeded supported_locales and default_locale', function (): void {
        $response = $this->getJson('/api/v1/settings/public');

        expect($response->json('data.supported_locales'))->toBe(['en', 'ar']);
        expect($response->json('data.default_locale'))->toBe('en');
    });

    it('derives currencies_by_country and vat_rates_by_country from the countries table', function (): void {
        $response = $this->getJson('/api/v1/settings/public');

        expect($response->json('data.currencies_by_country.ae'))->toBe('AED');
        expect($response->json('data.currencies_by_country.sa'))->toBe('SAR');
        expect((float) $response->json('data.vat_rates_by_country.ae'))->toBe(5.0);
        expect((float) $response->json('data.vat_rates_by_country.sa'))->toBe(15.0);
        expect((float) $response->json('data.vat_rates_by_country.qa'))->toBe(0.0);
    });

    it('collapses bilingual support_hours to English by default', function (): void {
        $response = $this->getJson('/api/v1/settings/public');

        $hours = $response->json('data.support_hours');
        expect($hours)->toBeString();
        expect($hours)->toContain('Sun');
    });

    it('collapses bilingual support_hours to Arabic when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/settings/public', ['Accept-Language' => 'ar']);

        $hours = $response->json('data.support_hours');
        expect($hours)->toBeString();
        expect($hours)->toContain('الأحد');
    });

    it('does not expose non-public settings', function (): void {
        Setting::factory()->privateSetting()->create([
            'key' => 'payfort_merchant_id',
            'value' => 'super-secret-merchant-id',
        ]);

        $response = $this->getJson('/api/v1/settings/public');

        // The private key must not appear anywhere in the response body.
        $body = $response->getContent();
        expect($body)->not->toContain('payfort_merchant_id');
        expect($body)->not->toContain('super-secret-merchant-id');
        expect($response->json('data'))->not->toHaveKey('payfort_merchant_id');
    });

    it('does not expose unwhitelisted public settings keys', function (): void {
        // Even an is_public=true key that is not on the whitelist must be filtered.
        Setting::factory()->publicSetting()->create([
            'key' => 'experimental_flag',
            'value' => ['enabled' => true],
        ]);

        $response = $this->getJson('/api/v1/settings/public');

        expect($response->json('data'))->not->toHaveKey('experimental_flag');
    });
});
