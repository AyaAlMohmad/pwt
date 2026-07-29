<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Testimonial;
use Illuminate\Database\Seeder;

/**
 * Seed 4 demo testimonials. Env-gated to local/development/testing.
 *
 * Idempotency is keyed on the (name, company) tuple — the simplest
 * proxy for "the same testimonial" until admin CRUD (Phase 20)
 * introduces stable codes.
 */
final class TestimonialsSeeder extends Seeder
{
    public function run(): void
    {
        $env = app()->environment();
        if (! in_array($env, ['local', 'development', 'testing'], true)) {
            $this->command?->warn(
                "[TestimonialsSeeder] Skipped: refusing to seed demo testimonials in '$env' environment.",
            );

            return;
        }

        foreach ($this->testimonials() as $row) {
            Testimonial::updateOrCreate(
                ['name' => $row['name'], 'company' => $row['company']],
                $row,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function testimonials(): array
    {
        return [
            [
                'name' => 'Sara Al Mansouri',
                'role' => 'Office Manager',
                'company' => 'Acme Trading LLC',
                'avatar_url' => null,
                'rating' => 5,
                'quote' => [
                    'en' => "PWT's filtration system completely changed our office water quality. The team is incredibly responsive.",
                    'ar' => 'لقد غيّر نظام التنقية من PWT جودة المياه في مكتبنا بالكامل. الفريق سريع الاستجابة بشكل مذهل.',
                ],
                'display_order' => 0,
                'is_active' => true,
            ],
            [
                'name' => 'Mohammed Hassan',
                'role' => 'Facility Director',
                'company' => 'Gulf Hospitality Group',
                'avatar_url' => null,
                'rating' => 5,
                'quote' => [
                    'en' => 'Three years with PWT and zero complaints from our guests. Reliable installation and maintenance every time.',
                    'ar' => 'ثلاث سنوات مع PWT وصفر شكاوى من ضيوفنا. تركيب وصيانة موثوق به في كل مرة.',
                ],
                'display_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Layla Al Bloushi',
                'role' => 'Homeowner',
                'company' => null,
                'avatar_url' => null,
                'rating' => 4,
                'quote' => [
                    'en' => 'Easy to book and the technician arrived right on time. Our kitchen tap finally tastes like a bottled spring.',
                    'ar' => 'سهل الحجز والفني وصل في الموعد بالضبط. أصبح طعم ماء صنبور المطبخ مثل ماء الينابيع المعبأ.',
                ],
                'display_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Khaled Al Saud',
                'role' => 'Operations Lead',
                'company' => 'Northern Pharma',
                'avatar_url' => null,
                'rating' => 5,
                'quote' => [
                    'en' => 'We standardised all our regional offices on PWT. Service quality is consistent across UAE, Saudi, and Qatar.',
                    'ar' => 'لقد اعتمدنا PWT كمعيار في جميع مكاتبنا الإقليمية. جودة الخدمة متناسقة عبر الإمارات والسعودية وقطر.',
                ],
                'display_order' => 3,
                'is_active' => true,
            ],
        ];
    }
}
