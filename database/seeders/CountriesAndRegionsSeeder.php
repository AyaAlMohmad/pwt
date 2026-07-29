<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Seed the 6 GCC countries and their administrative regions.
 *
 * Idempotent via `updateOrCreate`. Country attributes (VAT rate,
 * currency, phone code) are always refreshed to keep the seeder
 * authoritative; region attributes are only inserted when missing,
 * so any admin edits to display names survive a re-seed.
 */
final class CountriesAndRegionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->countries() as $row) {
            /** @var array{code: string, name: array{en: string, ar: string}, phone_code: string, currency: string, vat_rate: float, regions: list<array{code: ?string, name: array{en: string, ar: string}}>} $row */
            $country = Country::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'phone_code' => $row['phone_code'],
                    'currency' => $row['currency'],
                    'vat_rate' => $row['vat_rate'],
                    'is_active' => true,
                ],
            );

            foreach ($row['regions'] as $region) {
                Region::firstOrCreate(
                    [
                        'country_id' => $country->id,
                        'code' => $region['code'],
                    ],
                    [
                        'name' => $region['name'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * Canonical country + region data.
     *
     * @return list<array{
     *     code: string,
     *     name: array{en: string, ar: string},
     *     phone_code: string,
     *     currency: string,
     *     vat_rate: float,
     *     regions: list<array{code: ?string, name: array{en: string, ar: string}}>
     * }>
     */
    private function countries(): array
    {
        return [
            [
                'code' => 'ae',
                'name' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة'],
                'phone_code' => '+971',
                'currency' => 'AED',
                'vat_rate' => 5.00,
                'regions' => [
                    ['code' => 'auh', 'name' => ['en' => 'Abu Dhabi', 'ar' => 'أبو ظبي']],
                    ['code' => 'dxb', 'name' => ['en' => 'Dubai', 'ar' => 'دبي']],
                    ['code' => 'shj', 'name' => ['en' => 'Sharjah', 'ar' => 'الشارقة']],
                    ['code' => 'ajm', 'name' => ['en' => 'Ajman', 'ar' => 'عجمان']],
                    ['code' => 'uaq', 'name' => ['en' => 'Umm Al Quwain', 'ar' => 'أم القيوين']],
                    ['code' => 'rak', 'name' => ['en' => 'Ras Al Khaimah', 'ar' => 'رأس الخيمة']],
                    ['code' => 'fuj', 'name' => ['en' => 'Fujairah', 'ar' => 'الفجيرة']],
                ],
            ],
            [
                'code' => 'sa',
                'name' => ['en' => 'Saudi Arabia', 'ar' => 'المملكة العربية السعودية'],
                'phone_code' => '+966',
                'currency' => 'SAR',
                'vat_rate' => 15.00,
                'regions' => [
                    ['code' => 'ruh', 'name' => ['en' => 'Riyadh', 'ar' => 'الرياض']],
                    ['code' => 'mak', 'name' => ['en' => 'Makkah', 'ar' => 'مكة المكرمة']],
                    ['code' => 'med', 'name' => ['en' => 'Madinah', 'ar' => 'المدينة المنورة']],
                    ['code' => 'epr', 'name' => ['en' => 'Eastern Province', 'ar' => 'المنطقة الشرقية']],
                    ['code' => 'asr', 'name' => ['en' => 'Asir', 'ar' => 'عسير']],
                    ['code' => 'tbk', 'name' => ['en' => 'Tabuk', 'ar' => 'تبوك']],
                    ['code' => 'qsm', 'name' => ['en' => 'Qassim', 'ar' => 'القصيم']],
                    ['code' => 'hai', 'name' => ['en' => 'Hail', 'ar' => 'حائل']],
                    ['code' => 'nbr', 'name' => ['en' => 'Northern Borders', 'ar' => 'الحدود الشمالية']],
                    ['code' => 'jzn', 'name' => ['en' => 'Jazan', 'ar' => 'جازان']],
                    ['code' => 'njr', 'name' => ['en' => 'Najran', 'ar' => 'نجران']],
                    ['code' => 'bah', 'name' => ['en' => 'Al Bahah', 'ar' => 'الباحة']],
                    ['code' => 'jof', 'name' => ['en' => 'Al Jouf', 'ar' => 'الجوف']],
                ],
            ],
            [
                'code' => 'qa',
                'name' => ['en' => 'Qatar', 'ar' => 'قطر'],
                'phone_code' => '+974',
                'currency' => 'QAR',
                'vat_rate' => 0.00,
                'regions' => [
                    ['code' => 'doh', 'name' => ['en' => 'Ad-Dawhah (Doha)', 'ar' => 'الدوحة']],
                    ['code' => 'ray', 'name' => ['en' => 'Al Rayyan', 'ar' => 'الريان']],
                    ['code' => 'wak', 'name' => ['en' => 'Al Wakrah', 'ar' => 'الوكرة']],
                    ['code' => 'khr', 'name' => ['en' => 'Al Khor', 'ar' => 'الخور']],
                    ['code' => 'ums', 'name' => ['en' => 'Umm Salal', 'ar' => 'أم صلال']],
                    ['code' => 'day', 'name' => ['en' => 'Al Daayen', 'ar' => 'الضعاين']],
                    ['code' => 'sha', 'name' => ['en' => 'Al-Shahaniya', 'ar' => 'الشحانية']],
                    ['code' => 'shm', 'name' => ['en' => 'Al-Shamal', 'ar' => 'الشمال']],
                ],
            ],
            [
                'code' => 'kw',
                'name' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
                'phone_code' => '+965',
                'currency' => 'KWD',
                'vat_rate' => 0.00,
                'regions' => [
                    ['code' => 'kwi', 'name' => ['en' => 'Al Asimah (Capital)', 'ar' => 'العاصمة']],
                    ['code' => 'hwl', 'name' => ['en' => 'Hawalli', 'ar' => 'حولي']],
                    ['code' => 'far', 'name' => ['en' => 'Farwaniya', 'ar' => 'الفروانية']],
                    ['code' => 'mkb', 'name' => ['en' => 'Mubarak Al-Kabeer', 'ar' => 'مبارك الكبير']],
                    ['code' => 'ahm', 'name' => ['en' => 'Ahmadi', 'ar' => 'الأحمدي']],
                    ['code' => 'jhr', 'name' => ['en' => 'Jahra', 'ar' => 'الجهراء']],
                ],
            ],
            [
                'code' => 'bh',
                'name' => ['en' => 'Bahrain', 'ar' => 'البحرين'],
                'phone_code' => '+973',
                'currency' => 'BHD',
                'vat_rate' => 10.00,
                'regions' => [
                    ['code' => 'cap', 'name' => ['en' => 'Capital', 'ar' => 'العاصمة']],
                    ['code' => 'muh', 'name' => ['en' => 'Muharraq', 'ar' => 'المحرق']],
                    ['code' => 'nor', 'name' => ['en' => 'Northern', 'ar' => 'الشمالية']],
                    ['code' => 'sou', 'name' => ['en' => 'Southern', 'ar' => 'الجنوبية']],
                ],
            ],
            [
                'code' => 'om',
                'name' => ['en' => 'Oman', 'ar' => 'عُمان'],
                'phone_code' => '+968',
                'currency' => 'OMR',
                'vat_rate' => 5.00,
                'regions' => [
                    ['code' => 'mct', 'name' => ['en' => 'Muscat', 'ar' => 'مسقط']],
                    ['code' => 'dho', 'name' => ['en' => 'Dhofar', 'ar' => 'ظفار']],
                    ['code' => 'mus', 'name' => ['en' => 'Musandam', 'ar' => 'مسندم']],
                    ['code' => 'bur', 'name' => ['en' => 'Al Buraimi', 'ar' => 'البريمي']],
                    ['code' => 'dak', 'name' => ['en' => 'Al Dakhiliyah', 'ar' => 'الداخلية']],
                    ['code' => 'dhh', 'name' => ['en' => 'Al Dhahirah', 'ar' => 'الظاهرة']],
                    ['code' => 'btn', 'name' => ['en' => 'Al Batinah North', 'ar' => 'شمال الباطنة']],
                    ['code' => 'bts', 'name' => ['en' => 'Al Batinah South', 'ar' => 'جنوب الباطنة']],
                    ['code' => 'shn', 'name' => ['en' => 'Al Sharqiyah North', 'ar' => 'شمال الشرقية']],
                    ['code' => 'shs', 'name' => ['en' => 'Al Sharqiyah South', 'ar' => 'جنوب الشرقية']],
                    ['code' => 'wus', 'name' => ['en' => 'Al Wusta', 'ar' => 'الوسطى']],
                ],
            ],
        ];
    }
}
