<?php

declare(strict_types=1);

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use App\Enums\PromotionReasonCode;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Promotions\PromotionEngine;
use App\Services\Promotions\PromotionValidationDto;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Unit — PromotionEngine
|--------------------------------------------------------------------------
|
| 100% method coverage target. The engine is the single source of truth
| for promo evaluation, shared by Phase 7 (`/validate`) and Phase 8
| (OrderService::createOrder). Tests run on the MySQL `pwt_testing`
| pipeline (see tests/Pest.php).
*/

beforeEach(function (): void {
    $this->engine = new PromotionEngine;
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CategoriesSeeder::class);
});

/*
|--------------------------------------------------------------------------
| listActive()
|--------------------------------------------------------------------------
*/

describe('PromotionEngine::listActive()', function (): void {
    it('returns only currently-active promotions', function (): void {
        Promotion::factory()->active()->appliesToAll()->create(['code' => 'A1']);
        Promotion::factory()->inactive()->appliesToAll()->create(['code' => 'A2']);
        Promotion::factory()->expired()->appliesToAll()->create(['code' => 'A3']);
        Promotion::factory()->upcoming()->appliesToAll()->create(['code' => 'A4']);

        $codes = $this->engine->listActive()->pluck('code')->all();

        expect($codes)->toContain('A1')
            ->and($codes)->not->toContain('A2')
            ->and($codes)->not->toContain('A3')
            ->and($codes)->not->toContain('A4');
    });

    it('orders results by starts_at DESC, id DESC', function (): void {
        Carbon::setTestNow(now());
        $older = Promotion::factory()->active()->create([
            'code' => 'OLDER',
            'starts_at' => now()->subDays(10),
        ]);
        $newer = Promotion::factory()->active()->create([
            'code' => 'NEWER',
            'starts_at' => now()->subDay(),
        ]);
        Carbon::setTestNow();

        $codes = $this->engine->listActive()->pluck('code')->all();
        $newerIdx = array_search('NEWER', $codes, true);
        $olderIdx = array_search('OLDER', $codes, true);

        expect($newerIdx)->toBeLessThan($olderIdx);
    });
});

/*
|--------------------------------------------------------------------------
| findActiveByCode()
|--------------------------------------------------------------------------
*/

describe('PromotionEngine::findActiveByCode()', function (): void {
    it('returns the promotion when the code matches case-insensitively', function (): void {
        Promotion::factory()->active()->create(['code' => 'WELCOME10']);

        $byLower = $this->engine->findActiveByCode('welcome10');
        $byPadded = $this->engine->findActiveByCode('  WeLcOmE10 ');

        expect($byLower)->not->toBeNull()
            ->and($byLower->code)->toBe('WELCOME10');
        expect($byPadded)->not->toBeNull()
            ->and($byPadded->code)->toBe('WELCOME10');
    });

    it('returns null when the code does not exist', function (): void {
        expect($this->engine->findActiveByCode('NOPE'))->toBeNull();
    });

    it('returns null for an empty / whitespace-only string', function (): void {
        expect($this->engine->findActiveByCode(''))->toBeNull();
        expect($this->engine->findActiveByCode('   '))->toBeNull();
    });

    it('returns null when the code matches an inactive promotion', function (): void {
        Promotion::factory()->inactive()->create(['code' => 'INACT']);

        expect($this->engine->findActiveByCode('INACT'))->toBeNull();
    });

    it('returns null when the code matches an expired promotion', function (): void {
        Promotion::factory()->expired()->create(['code' => 'EXPIRED']);

        expect($this->engine->findActiveByCode('EXPIRED'))->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| countActiveForProduct()
|--------------------------------------------------------------------------
*/

describe('PromotionEngine::countActiveForProduct()', function (): void {
    beforeEach(function (): void {
        $category = Category::query()->where('code', 'counter-top-filters')->firstOrFail();
        $this->category = $category;
        $this->product = Product::factory()->forCategory($category)->create([
            'code' => 'UNIT-A',
        ]);
        $this->otherProduct = Product::factory()->forCategory($category)->create([
            'code' => 'UNIT-B',
        ]);
    });

    it('counts applies_to=all promotions for the product', function (): void {
        Promotion::factory()->active()->appliesToAll()->create(['code' => 'ALL-1']);
        Promotion::factory()->active()->appliesToAll()->create(['code' => 'ALL-2']);

        $count = $this->engine->countActiveForProduct($this->product->id, $this->product->category_id);

        expect($count)->toBe(2);
    });

    it('counts a product-scoped promotion when the product IS in the pivot', function (): void {
        $promo = Promotion::factory()->active()->appliesToProducts()->create(['code' => 'P-IN']);
        $promo->products()->attach($this->product->id);

        $count = $this->engine->countActiveForProduct($this->product->id, $this->product->category_id);

        expect($count)->toBe(1);
    });

    it('skips a product-scoped promotion when the product is NOT in the pivot', function (): void {
        $promo = Promotion::factory()->active()->appliesToProducts()->create(['code' => 'P-OUT']);
        $promo->products()->attach($this->otherProduct->id);

        $count = $this->engine->countActiveForProduct($this->product->id, $this->product->category_id);

        expect($count)->toBe(0);
    });

    it('counts a category-scoped promotion when the category IS in the pivot', function (): void {
        $promo = Promotion::factory()->active()->appliesToCategories()->create(['code' => 'C-IN']);
        $promo->categories()->attach($this->product->category_id);

        $count = $this->engine->countActiveForProduct($this->product->id, $this->product->category_id);

        expect($count)->toBe(1);
    });

    it('skips a category-scoped promotion when the category is NOT in the pivot', function (): void {
        $otherCategory = Category::query()->where('code', 'commercial')->firstOrFail();
        $promo = Promotion::factory()->active()->appliesToCategories()->create(['code' => 'C-OUT']);
        $promo->categories()->attach($otherCategory->id);

        $count = $this->engine->countActiveForProduct($this->product->id, $this->product->category_id);

        expect($count)->toBe(0);
    });

    it('skips category-scoped promotions when categoryId is null', function (): void {
        $promo = Promotion::factory()->active()->appliesToCategories()->create(['code' => 'C-NULL']);
        $promo->categories()->attach($this->category->id);

        $count = $this->engine->countActiveForProduct($this->product->id, null);

        expect($count)->toBe(0);
    });

    it('does NOT count inactive / expired / upcoming / soft-deleted promotions', function (): void {
        Promotion::factory()->inactive()->appliesToAll()->create(['code' => 'X-INACT']);
        Promotion::factory()->expired()->appliesToAll()->create(['code' => 'X-EXP']);
        Promotion::factory()->upcoming()->appliesToAll()->create(['code' => 'X-UPC']);
        $soft = Promotion::factory()->active()->appliesToAll()->create(['code' => 'X-SOFT']);
        $soft->delete();

        $count = $this->engine->countActiveForProduct($this->product->id, $this->product->category_id);

        expect($count)->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| validate() — soft-fail branches
|--------------------------------------------------------------------------
*/

describe('PromotionEngine::validate() — soft-fail branches', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->customer()->create();
    });

    it('returns reason=CodeUnknown when the code does not exist', function (): void {
        $dto = $this->engine->validate('NOPE', $this->user, 500.0, 'AED');

        expect($dto)->toBeInstanceOf(PromotionValidationDto::class);
        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::CodeUnknown)
            ->and($dto->promotionId)->toBeNull()
            ->and($dto->appliedAmount)->toBeNull();
    });

    it('returns reason=Inactive when the promotion has is_active=false', function (): void {
        $promo = Promotion::factory()->inactive()->create(['code' => 'INACT']);

        $dto = $this->engine->validate('INACT', $this->user, 500.0, 'AED');

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::Inactive)
            ->and($dto->promotionId)->toBe($promo->id);
    });

    it('returns reason=NotStarted when starts_at is in the future', function (): void {
        $promo = Promotion::factory()->upcoming()->create(['code' => 'UPC']);

        $dto = $this->engine->validate('UPC', $this->user, 500.0, 'AED');

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::NotStarted)
            ->and($dto->promotionId)->toBe($promo->id);
    });

    it('returns reason=Expired when ends_at is in the past', function (): void {
        $promo = Promotion::factory()->expired()->create(['code' => 'EXP']);

        $dto = $this->engine->validate('EXP', $this->user, 500.0, 'AED');

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::Expired)
            ->and($dto->promotionId)->toBe($promo->id);
    });

    it('returns reason=CurrencyMismatch for a fixed promo when currencies differ', function (): void {
        $promo = Promotion::factory()->active()->fixed(50.0, 'AED')->appliesToAll()
            ->create(['code' => 'FIXAED']);

        $dto = $this->engine->validate('FIXAED', $this->user, 1000.0, 'SAR');

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::CurrencyMismatch)
            ->and($dto->promotionId)->toBe($promo->id);
    });

    it('does NOT trigger CurrencyMismatch for percent promos (currency-agnostic)', function (): void {
        Promotion::factory()->active()->percent(10.0)->appliesToAll()
            ->withPerUserLimit(null)
            ->create(['code' => 'PCT']);

        $dto = $this->engine->validate('PCT', $this->user, 1000.0, 'SAR');

        // Percent is currency-agnostic — currency mismatch is bypassed.
        expect($dto->valid)->toBeTrue()
            ->and($dto->currency)->toBe('SAR');
    });

    it('returns reason=MinOrderNotMet when subtotal < min_order_amount', function (): void {
        Promotion::factory()->active()->fixed(50.0, 'AED')->withMinOrder(500.0)
            ->appliesToAll()->withPerUserLimit(null)
            ->create(['code' => 'MIN500']);

        $dto = $this->engine->validate('MIN500', $this->user, 100.0, 'AED');

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::MinOrderNotMet);
    });

    it('returns reason=ScopeMismatch for applies_to=products with no overlapping product_ids', function (): void {
        $promo = Promotion::factory()->active()->appliesToProducts()
            ->withPerUserLimit(null)
            ->create(['code' => 'PSCOPE']);
        // Attach product 999 — caller's cart is empty, so no overlap.
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();
        $product = Product::factory()->forCategory($cat)->create(['code' => 'P-X']);
        $promo->products()->attach($product->id);

        $dto = $this->engine->validate('PSCOPE', $this->user, 500.0, 'AED', productIds: []);

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::ScopeMismatch);
    });

    it('passes scope check for applies_to=products when product_ids overlap', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();
        $product = Product::factory()->forCategory($cat)->create(['code' => 'P-Y']);

        $promo = Promotion::factory()->active()->appliesToProducts()
            ->percent(10.0)->withPerUserLimit(null)
            ->create(['code' => 'PMATCH']);
        $promo->products()->attach($product->id);

        $dto = $this->engine->validate('PMATCH', $this->user, 500.0, 'AED', productIds: [$product->id]);

        expect($dto->valid)->toBeTrue();
    });

    it('returns ScopeMismatch for applies_to=categories with no overlapping category_ids', function (): void {
        $cat = Category::query()->where('code', 'commercial')->firstOrFail();
        $promo = Promotion::factory()->active()->appliesToCategories()
            ->withPerUserLimit(null)
            ->create(['code' => 'CSCOPE']);
        $promo->categories()->attach($cat->id);

        $dto = $this->engine->validate('CSCOPE', $this->user, 500.0, 'AED', categoryIds: [9999]);

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::ScopeMismatch);
    });

    it('passes scope check for applies_to=categories when category_ids overlap', function (): void {
        $cat = Category::query()->where('code', 'commercial')->firstOrFail();
        $promo = Promotion::factory()->active()->appliesToCategories()
            ->percent(10.0)->withPerUserLimit(null)
            ->create(['code' => 'CMATCH']);
        $promo->categories()->attach($cat->id);

        $dto = $this->engine->validate('CMATCH', $this->user, 500.0, 'AED', categoryIds: [$cat->id]);

        expect($dto->valid)->toBeTrue();
    });

    it('returns reason=TotalLimitReached when total_limit is exhausted', function (): void {
        $promo = Promotion::factory()->active()->appliesToAll()
            ->withTotalLimit(2)
            ->withPerUserLimit(null)
            ->create(['code' => 'TOT2']);

        $u2 = User::factory()->customer()->create();
        PromotionRedemption::factory()->forPromotion($promo)->forUser($u2)->create();
        PromotionRedemption::factory()->forPromotion($promo)->forUser($u2)->create();

        $dto = $this->engine->validate('TOT2', $this->user, 500.0, 'AED');

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::TotalLimitReached);
    });

    it('returns reason=PerUserLimitReached when the per-user count is at the cap', function (): void {
        $promo = Promotion::factory()->active()->appliesToAll()
            ->withPerUserLimit(1)
            ->create(['code' => 'PUL1']);
        PromotionRedemption::factory()->forPromotion($promo)->forUser($this->user)->create();

        $dto = $this->engine->validate('PUL1', $this->user, 500.0, 'AED');

        expect($dto->valid)->toBeFalse()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::PerUserLimitReached);
    });

    it('skips the per-user limit check when per_user_limit is null (unlimited)', function (): void {
        $promo = Promotion::factory()->active()->appliesToAll()
            ->withPerUserLimit(null)
            ->create(['code' => 'PULUNL']);
        // Even 5 prior redemptions shouldn't block — per_user_limit is null.
        PromotionRedemption::factory()->count(5)->forPromotion($promo)->forUser($this->user)->create();

        $dto = $this->engine->validate('PULUNL', $this->user, 500.0, 'AED');

        expect($dto->valid)->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| validate() — happy path / applied_amount computation
|--------------------------------------------------------------------------
*/

describe('PromotionEngine::validate() — applied_amount computation', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->customer()->create();
    });

    it('computes percent discount as subtotal × value/100', function (): void {
        Promotion::factory()->active()->percent(15.0)->appliesToAll()
            ->withPerUserLimit(null)
            ->create(['code' => 'PCT15']);

        $dto = $this->engine->validate('PCT15', $this->user, 200.0, 'AED');

        expect($dto->valid)->toBeTrue();
        // 15% × 200 = 30.
        expect($dto->appliedAmount)->toBe(30.0);
        expect($dto->discountType)->toBe(DiscountType::Percent);
        expect($dto->currency)->toBe('AED');
    });

    it('honours max_discount_amount for percent promos', function (): void {
        Promotion::factory()->active()->percent(50.0, 100.0)->appliesToAll()
            ->withPerUserLimit(null)
            ->create(['code' => 'CAPPED']);

        $dto = $this->engine->validate('CAPPED', $this->user, 1000.0, 'AED');

        expect($dto->valid)->toBeTrue();
        // Raw = 500, capped at 100.
        expect($dto->appliedAmount)->toBe(100.0);
    });

    it('returns the fixed value verbatim for fixed promos', function (): void {
        Promotion::factory()->active()->fixed(75.0, 'AED')->appliesToAll()
            ->withPerUserLimit(null)
            ->create(['code' => 'FIX75']);

        $dto = $this->engine->validate('FIX75', $this->user, 500.0, 'AED');

        expect($dto->valid)->toBeTrue();
        expect($dto->appliedAmount)->toBe(75.0);
        expect($dto->discountType)->toBe(DiscountType::Fixed);
    });

    it('clamps applied_amount to the subtotal so the customer never owes negative money', function (): void {
        // Fixed AED 500 promo with min_order 100. Customer has subtotal 200.
        // The 500 fixed discount must clamp down to 200 (subtotal).
        Promotion::factory()->active()->fixed(500.0, 'AED')->appliesToAll()
            ->withMinOrder(100.0)
            ->withPerUserLimit(null)
            ->create(['code' => 'BIGFIX']);

        $dto = $this->engine->validate('BIGFIX', $this->user, 200.0, 'AED');

        expect($dto->valid)->toBeTrue();
        expect($dto->appliedAmount)->toBe(200.0);
    });

    it('populates the success DTO with the correct allowlisted fields', function (): void {
        $promo = Promotion::factory()->active()->percent(20.0)->appliesToAll()
            ->withPerUserLimit(null)
            ->create(['code' => 'PCT20', 'currency' => null]);

        $dto = $this->engine->validate('PCT20', $this->user, 100.0, 'AED');

        expect($dto->valid)->toBeTrue()
            ->and($dto->code)->toBe('PCT20')
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::Applied)
            ->and($dto->promotionId)->toBe($promo->id)
            ->and($dto->discountType)->toBe(DiscountType::Percent)
            ->and($dto->discountValue)->toBe(20.0)
            ->and($dto->discountCurrency)->toBeNull()
            ->and($dto->appliesTo)->toBe(PromoScope::All)
            ->and($dto->appliedAmount)->toBe(20.0)
            ->and($dto->currency)->toBe('AED');
    });
});

/*
|--------------------------------------------------------------------------
| DTO factory methods — small but worth pinning
|--------------------------------------------------------------------------
*/

describe('PromotionValidationDto factories', function (): void {
    it('fail() builds a soft-fail DTO with no discount fields', function (): void {
        $dto = PromotionValidationDto::fail('X', PromotionReasonCode::CodeUnknown, 'missing');

        expect($dto->valid)->toBeFalse()
            ->and($dto->code)->toBe('X')
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::CodeUnknown)
            ->and($dto->message)->toBe('missing')
            ->and($dto->promotionId)->toBeNull()
            ->and($dto->discountType)->toBeNull()
            ->and($dto->appliedAmount)->toBeNull();
    });

    it('success() builds a fully-populated DTO with reasonCode=Applied', function (): void {
        $dto = PromotionValidationDto::success(
            code: 'OK',
            promotionId: 7,
            discountType: DiscountType::Percent,
            discountValue: 10.0,
            discountCurrency: null,
            appliesTo: PromoScope::All,
            appliedAmount: 10.0,
            currency: 'AED',
            message: 'applied',
        );

        expect($dto->valid)->toBeTrue()
            ->and($dto->reasonCode)->toBe(PromotionReasonCode::Applied)
            ->and($dto->promotionId)->toBe(7)
            ->and($dto->appliedAmount)->toBe(10.0)
            ->and($dto->currency)->toBe('AED')
            ->and($dto->appliesTo)->toBe(PromoScope::All);
    });
});
