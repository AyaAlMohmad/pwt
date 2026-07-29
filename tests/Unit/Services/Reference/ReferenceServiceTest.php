<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\Region;
use App\Models\Setting;
use App\Services\Reference\ReferenceService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;

// Unit tests in this file exercise the database, so RefreshDatabase is needed.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = new ReferenceService;
});

describe('ReferenceService::listActiveCountries', function (): void {
    it('returns only active countries ordered by code', function (): void {
        Country::factory()->active()->create(['code' => 'sa', 'name' => ['en' => 'Saudi', 'ar' => 'سعودية']]);
        Country::factory()->active()->create(['code' => 'ae', 'name' => ['en' => 'UAE', 'ar' => 'الإمارات']]);
        Country::factory()->inactive()->create(['code' => 'zz', 'name' => ['en' => 'Hidden', 'ar' => 'مخفية']]);

        $result = $this->service->listActiveCountries();

        $codes = $result->pluck('code')->all();
        expect($codes)->toBe(['ae', 'sa']);
        expect($codes)->not->toContain('zz');
    });

    it('returns an empty collection when no countries are seeded', function (): void {
        $result = $this->service->listActiveCountries();
        expect($result)->toBeEmpty();
    });

    it('returns the seeded GCC countries in alphabetical code order', function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);

        $result = $this->service->listActiveCountries();

        $codes = $result->pluck('code')->all();
        expect($codes)->toBe(['ae', 'bh', 'kw', 'om', 'qa', 'sa']);
    });
});

describe('ReferenceService::regionsForCountry', function (): void {
    beforeEach(function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);
    });

    it('returns regions for a valid lowercase code', function (): void {
        $result = $this->service->regionsForCountry('ae');

        expect($result)->toHaveCount(7);
        expect($result->first())->toBeInstanceOf(Region::class);
    });

    it('honors the lowercase contract (input must already be lowercased)', function (): void {
        // The controller lowercases before calling the service; given a
        // lowercase value, the service returns rows. Given an already
        // lowercase 'AE' transformed by the controller, the service hits
        // the same path. We assert that 'AE' passed directly to the
        // service does NOT find the country (since lookup is exact-match
        // against the lowercase stored code).
        expect(fn () => $this->service->regionsForCountry('AE'))
            ->toThrow(HttpResponseException::class);
    });

    it('throws an HttpResponseException with a 404 envelope for unknown codes', function (): void {
        try {
            $this->service->regionsForCountry('zz');
            // Should not reach this line.
            expect(false)->toBeTrue('Expected HttpResponseException was not thrown');
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            expect($response->getStatusCode())->toBe(404);

            $payload = json_decode((string) $response->getContent(), true);
            expect($payload)->toBeArray();
            expect($payload['success'])->toBeFalse();
            expect($payload['code'])->toBe('ERR_COUNTRY_NOT_FOUND');
        }
    });

    it('throws for an inactive country', function (): void {
        Country::query()->where('code', 'ae')->update(['is_active' => false]);

        expect(fn () => $this->service->regionsForCountry('ae'))
            ->toThrow(HttpResponseException::class);
    });

    it('excludes inactive regions from the result', function (): void {
        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        Region::factory()->inactive()->create([
            'country_id' => $uae->id,
            'name' => ['en' => 'Hidden Emirate', 'ar' => 'مخفي'],
        ]);

        $result = $this->service->regionsForCountry('ae');

        // Still 7 emirates — inactive one filtered out.
        expect($result)->toHaveCount(7);
        foreach ($result as $region) {
            expect($region->is_active)->toBeTrue();
        }
    });
});

describe('ReferenceService::publicSettings', function (): void {
    it('returns only whitelisted public keys plus the derived per-country maps', function (): void {
        $this->seed(SettingsSeeder::class);
        $this->seed(CountriesAndRegionsSeeder::class);

        $result = $this->service->publicSettings();

        $expectedKeys = [
            'supported_locales',
            'default_locale',
            'whatsapp_number',
            'contact_email',
            'social_links',
            'support_hours',
            'currencies_by_country',
            'vat_rates_by_country',
        ];

        foreach ($expectedKeys as $key) {
            expect($result)->toHaveKey($key);
        }
    });

    it('filters out is_public=false keys', function (): void {
        $this->seed(SettingsSeeder::class);

        Setting::factory()->privateSetting()->create([
            'key' => 'payfort_merchant_id',
            'value' => 'super-secret',
        ]);

        $result = $this->service->publicSettings();

        expect($result)->not->toHaveKey('payfort_merchant_id');
    });

    it('filters out unwhitelisted keys even if is_public=true', function (): void {
        $this->seed(SettingsSeeder::class);

        Setting::factory()->publicSetting()->create([
            'key' => 'experimental_flag',
            'value' => ['enabled' => true],
        ]);

        $result = $this->service->publicSettings();

        expect($result)->not->toHaveKey('experimental_flag');
    });

    it('derives currencies_by_country from the countries table', function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);

        $result = $this->service->publicSettings();

        expect($result['currencies_by_country'])->toMatchArray([
            'ae' => 'AED',
            'sa' => 'SAR',
            'qa' => 'QAR',
            'kw' => 'KWD',
            'bh' => 'BHD',
            'om' => 'OMR',
        ]);
    });

    it('derives vat_rates_by_country as floats from the countries table', function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);

        $result = $this->service->publicSettings();

        expect($result['vat_rates_by_country']['ae'])->toBe(5.0);
        expect($result['vat_rates_by_country']['sa'])->toBe(15.0);
        expect($result['vat_rates_by_country']['qa'])->toBe(0.0);
        expect($result['vat_rates_by_country']['bh'])->toBe(10.0);
    });

    it('omits inactive countries from the derived per-country maps', function (): void {
        $this->seed(CountriesAndRegionsSeeder::class);
        Country::query()->where('code', 'ae')->update(['is_active' => false]);

        $result = $this->service->publicSettings();

        expect($result['currencies_by_country'])->not->toHaveKey('ae');
        expect($result['vat_rates_by_country'])->not->toHaveKey('ae');
    });
});
