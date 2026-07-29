<?php

declare(strict_types=1);

use App\Enums\PromotionReasonCode;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Database\Seeders\PromotionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/promotions/validate
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/promotions.md § 1.1, § 3.3, § 4.3, § 5.1.
| Auth required (Sanctum). Always returns HTTP 200 with `data.valid:bool`
| for ALL promo-rule failures (Stripe-style soft-fail). 4xx is reserved
| for auth / validation / rate-limit / server failures.
|
| Rate limit: 10/min per authenticated user (`promotions.validate`).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PromotionsSeeder::class);

    // Clear the validate-endpoint rate limiter buckets for the most
    // common keys used below. We clear per-id after the user is created
    // when relevant inside the rate-limit test itself.
    RateLimiter::clear('promotions.validate');
});

/**
 * Create a customer + access token. Each call clears the validate rate-
 * limit bucket for the new user so happy-path tests are isolated.
 *
 * @return array{user: User, token: string}
 */
function makeValidateCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    RateLimiter::clear('promotions.validate:'.$user->id);

    return ['user' => $user, 'token' => $token];
}

describe('POST /promotions/validate — happy paths', function (): void {
    it('returns 200 + valid:true + applied_amount=50 for WELCOME10 with subtotal 500', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'valid', 'code', 'reason_code', 'message',
                    'discount' => ['type', 'value', 'currency', 'applies_to'],
                    'applied_amount', 'currency', 'promotion_id',
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data.valid'))->toBeTrue()
            ->and($response->json('data.code'))->toBe('WELCOME10')
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::Applied->value)
            ->and((float) $response->json('data.applied_amount'))->toBe(50.0)
            ->and($response->json('data.currency'))->toBe('AED')
            ->and($response->json('data.discount.type'))->toBe('percent')
            ->and((float) $response->json('data.discount.value'))->toBe(10.0)
            ->and($response->json('data.discount.applies_to'))->toBe('all');
    });

    it('caps the percent discount at max_discount_amount (WELCOME10 with subtotal 5000 → 100)', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 5000.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeTrue();
        // Raw 10% × 5000 = 500, capped at max_discount_amount = 100.
        expect((float) $response->json('data.applied_amount'))->toBe(100.0);
    });

    it('returns valid:true + applied_amount=50 for SUMMER50 with subtotal 1000 (fixed AED 50, min AED 500)', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'SUMMER50',
                'subtotal' => 1000.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeTrue()
            ->and((float) $response->json('data.applied_amount'))->toBe(50.0)
            ->and($response->json('data.discount.type'))->toBe('fixed')
            ->and((float) $response->json('data.discount.value'))->toBe(50.0)
            ->and($response->json('data.discount.currency'))->toBe('AED');
    });

    it('normalises lowercase / whitespace-padded codes on the wire', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => '  welcome10  ',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.code'))->toBe('WELCOME10')
            ->and($response->json('data.valid'))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| Soft-fail outcomes (always 200 + reason_code)
|--------------------------------------------------------------------------
*/

describe('POST /promotions/validate — soft-fail outcomes', function (): void {
    it('returns reason_code=min_order_not_met when subtotal < min_order_amount', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'SUMMER50',
                'subtotal' => 100.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::MinOrderNotMet->value)
            ->and($response->json('data.applied_amount'))->toBeNull()
            ->and($response->json('data.discount'))->toBeNull();
    });

    it('returns reason_code=code_unknown when the code does not exist (200, not 404)', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'NOPE-DOES-NOT-EXIST',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::CodeUnknown->value)
            ->and($response->json('data.promotion_id'))->toBeNull();
    });

    it('returns reason_code=inactive when the matched promotion has is_active=false', function (): void {
        ['token' => $token] = makeValidateCustomer();
        Promotion::query()->where('code', 'WELCOME10')->update(['is_active' => false]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::Inactive->value);
    });

    it('returns reason_code=expired when Carbon::setTestNow puts us past ends_at', function (): void {
        ['token' => $token] = makeValidateCustomer();

        // Seeded WELCOME10 ends at now()+90 days. Advance 100 days.
        Carbon::setTestNow(now()->addDays(100));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        Carbon::setTestNow();

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::Expired->value);
    });

    it('returns reason_code=not_started when starts_at is still in the future', function (): void {
        ['token' => $token] = makeValidateCustomer();
        Promotion::factory()->upcoming()->create([
            'code' => 'UPCOMING25',
            'type' => 'percent',
            'value' => 25.00,
            'currency' => null,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'UPCOMING25',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::NotStarted->value);
    });

    it('returns reason_code=currency_mismatch for fixed promos when currencies differ', function (): void {
        ['token' => $token] = makeValidateCustomer();
        // SUMMER50 is a fixed AED 50 promo. Request currency=SAR triggers
        // the mismatch even though SAR is otherwise allowed.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'SUMMER50',
                'subtotal' => 1000.0,
                'currency' => 'SAR',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::CurrencyMismatch->value);
    });

    it('returns reason_code=per_user_limit_reached after one redemption (WELCOME10 has per_user_limit=1)', function (): void {
        ['user' => $user, 'token' => $token] = makeValidateCustomer();

        $welcome = Promotion::query()->where('code', 'WELCOME10')->firstOrFail();
        PromotionRedemption::factory()
            ->forPromotion($welcome)
            ->forUser($user)
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::PerUserLimitReached->value);
    });

    it('returns reason_code=total_limit_reached when total_limit redemptions are recorded', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $promo = Promotion::factory()->active()
            ->withTotalLimit(2)
            ->withPerUserLimit(null)
            ->create(['code' => 'TOTALLIMIT2']);

        // Fill the bucket with redemptions from two different users so the
        // per-user check passes for our caller. With total_limit=2, two
        // pre-existing rows is enough to fill it.
        $u1 = User::factory()->customer()->create();
        $u2 = User::factory()->customer()->create();
        PromotionRedemption::factory()->forPromotion($promo)->forUser($u1)->create();
        PromotionRedemption::factory()->forPromotion($promo)->forUser($u2)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'TOTALLIMIT2',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $response->assertOk();
        expect($response->json('data.valid'))->toBeFalse()
            ->and($response->json('data.reason_code'))->toBe(PromotionReasonCode::TotalLimitReached->value);
    });
});

/*
|--------------------------------------------------------------------------
| Hard failures — 401 / 422 / 429
|--------------------------------------------------------------------------
*/

describe('POST /promotions/validate — hard failures', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when no bearer token is supplied', function (): void {
        $response = $this->postJson('/api/v1/promotions/validate', [
            'code' => 'WELCOME10',
            'subtotal' => 500.0,
            'currency' => 'AED',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 422 ERR_VALIDATION when required fields are missing', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
        expect($response->json('errors'))
            ->toHaveKey('code')
            ->toHaveKey('subtotal')
            ->toHaveKey('currency');
    });

    it('returns 422 when currency is not in the allow-list', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'USD',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
        expect($response->json('errors'))->toHaveKey('currency');
    });

    it('returns 422 when subtotal is negative', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => -1.00,
                'currency' => 'AED',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
        expect($response->json('errors'))->toHaveKey('subtotal');
    });

    it('returns 422 when product_ids references a non-existent product', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
                'product_ids' => [9999999],
            ]);

        $response->assertStatus(422);
        expect($response->json('errors'))->toHaveKey('product_ids.0');
    });

    it('returns 429 ERR_RATE_LIMITED after 10 calls in a minute', function (): void {
        ['user' => $user, 'token' => $token] = makeValidateCustomer();

        // 10 successful calls allowed within the window.
        for ($i = 0; $i < 10; $i++) {
            $hit = $this->withHeader('Authorization', "Bearer $token")
                ->postJson('/api/v1/promotions/validate', [
                    'code' => 'WELCOME10',
                    'subtotal' => 500.0,
                    'currency' => 'AED',
                ]);
            $hit->assertOk();
        }

        // The 11th call must trip the limiter.
        $overflow = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ]);

        $overflow->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_RATE_LIMITED');

        RateLimiter::clear('promotions.validate:'.$user->id);
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual
|--------------------------------------------------------------------------
*/

describe('POST /promotions/validate — bilingual', function (): void {
    it('returns the localized success envelope message in Arabic', function (): void {
        ['token' => $token] = makeValidateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ], ['Accept-Language' => 'ar']);

        $response->assertOk();
        expect($response->json('message'))->toBe('تم التحقق من رمز العرض.');
        // data.message is the customer-facing translated message — must
        // be the Arabic "applied" message.
        expect($response->json('data.message'))->toContain('تم تطبيق');
    });

    it('returns the Arabic "code expired" message when locale=ar and the code has expired', function (): void {
        ['token' => $token] = makeValidateCustomer();
        Carbon::setTestNow(now()->addDays(100));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/promotions/validate', [
                'code' => 'WELCOME10',
                'subtotal' => 500.0,
                'currency' => 'AED',
            ], ['Accept-Language' => 'ar']);

        Carbon::setTestNow();

        $response->assertOk();
        expect($response->json('data.message'))->toBe('هذا الرمز منتهي الصلاحية.');
    });
});
