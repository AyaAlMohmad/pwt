<?php

declare(strict_types=1);

use App\Http\Resources\Api\V1\Promotions\PromotionResource;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Database\Seeders\PromotionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| GET /api/v1/promotions
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/promotions.md § 1.1, § 4.2.
| Public, unpaginated, bounded. Returns only currently-active rows
| (is_active=true AND inside the start/end window). Sensitive admin-only
| fields (`total_limit`, `per_user_limit`) MUST NOT be serialised.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PromotionsSeeder::class);
    RateLimiter::clear('public');
});

describe('GET /api/v1/promotions — happy paths', function (): void {
    it('returns the 2 seeded active promotions in the standard envelope', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'code', 'name', 'description',
                        'type', 'value',
                        'max_discount_amount', 'currency', 'min_order_amount',
                        'starts_at', 'ends_at', 'applies_to',
                        'user_redemptions_remaining',
                    ],
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data'))->toHaveCount(2);
        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toContain('WELCOME10')->and($codes)->toContain('SUMMER50');
    });

    it('does not include meta.pagination (unpaginated bounded resource)', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        $response->assertOk();
        expect($response->json('meta'))->toBeNull();
    });

    it('orders rows by starts_at DESC then id DESC', function (): void {
        // SUMMER50 was inserted after WELCOME10 in the seeder but both have
        // the same starts_at (now->subDay). Higher id → SUMMER50 first.
        $response = $this->getJson('/api/v1/promotions');

        $codes = collect($response->json('data'))->pluck('code')->all();
        // Last inserted (highest id) ranks first when starts_at is equal.
        expect($codes[0])->toBe('SUMMER50');
        expect($codes[1])->toBe('WELCOME10');
    });

    it('does not require authentication (public endpoint)', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        $response->assertOk();
    });
});

/*
|--------------------------------------------------------------------------
| Visibility filtering
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/promotions — visibility', function (): void {
    it('hides inactive promotions', function (): void {
        Promotion::query()->where('code', 'WELCOME10')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/promotions');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('WELCOME10');
        expect($codes)->toContain('SUMMER50');
    });

    it('hides expired promotions', function (): void {
        Promotion::factory()->expired()->create(['code' => 'EXPIRED50']);

        $response = $this->getJson('/api/v1/promotions');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('EXPIRED50');
        // Seeded promos remain.
        expect($response->json('data'))->toHaveCount(2);
    });

    it('hides upcoming (not-started-yet) promotions', function (): void {
        Promotion::factory()->upcoming()->create(['code' => 'UPCOMING50']);

        $response = $this->getJson('/api/v1/promotions');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('UPCOMING50');
    });

    it('hides soft-deleted promotions', function (): void {
        Promotion::query()->where('code', 'WELCOME10')->firstOrFail()->delete();

        $response = $this->getJson('/api/v1/promotions');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('WELCOME10');
    });
});

/*
|--------------------------------------------------------------------------
| Sensitive-field masking
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/promotions — sensitive-field masking', function (): void {
    it('NEVER exposes total_limit or per_user_limit (admin-only)', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        foreach ($response->json('data') as $row) {
            expect($row)->not->toHaveKey('total_limit');
            expect($row)->not->toHaveKey('per_user_limit');
        }
    });

    it('does not leak created_at / updated_at / deleted_at', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        $row = $response->json('data.0');
        expect($row)->not->toHaveKey('created_at')
            ->and($row)->not->toHaveKey('updated_at')
            ->and($row)->not->toHaveKey('deleted_at');
    });

    it('exposes exactly the allowlisted keys', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        $row = $response->json('data.0');
        expect(array_keys($row))->toEqualCanonicalizing([
            'id', 'code', 'name', 'description',
            'type', 'value',
            'max_discount_amount', 'currency', 'min_order_amount',
            'starts_at', 'ends_at', 'applies_to',
            'user_redemptions_remaining',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| user_redemptions_remaining
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/promotions — user_redemptions_remaining', function (): void {
    /*
     * Contract per docs/architecture/promotions.md § 4.2:
     *
     *   `user_redemptions_remaining` is null on the public list call —
     *   /promotions has NO auth middleware so the resource cannot see
     *   the caller, and the spec explicitly notes the field is null
     *   for unauthenticated requests. The field is only meaningful on
     *   /promotions/validate, which is auth-gated.
     */

    it('returns null for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        foreach ($response->json('data') as $row) {
            expect($row['user_redemptions_remaining'])->toBeNull();
        }
    });

    it('still returns null when a bearer token is supplied — /promotions has no auth middleware', function (): void {
        // Even though the caller has a valid token, the public route
        // does NOT mount Sanctum, so `request()->user()` resolves to
        // null inside the resource. This is documented behaviour
        // (§ 4.2) — the redemption count is exposed on /validate, not
        // on the public list.
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/promotions');

        foreach ($response->json('data') as $row) {
            expect($row['user_redemptions_remaining'])->toBeNull();
        }
    });

    it('still returns null for an unlimited (per_user_limit=null) promo', function (): void {
        Promotion::factory()->active()->withPerUserLimit(null)->create([
            'code' => 'UNLIMITED',
        ]);

        $response = $this->getJson('/api/v1/promotions');

        $unlimited = collect($response->json('data'))->firstWhere('code', 'UNLIMITED');
        expect($unlimited)->not->toBeNull();
        expect($unlimited['user_redemptions_remaining'])->toBeNull();
    });

    it('still returns null even after the user has redeemed once (the route has no auth context)', function (): void {
        // The resource computes redemptions remaining only when a user
        // is attached to the request. Since /promotions is anonymous,
        // pre-existing redemptions are invisible to the response.
        $user = User::factory()->customer()->create();
        $welcome = Promotion::query()->where('code', 'WELCOME10')->firstOrFail();
        PromotionRedemption::factory()
            ->forPromotion($welcome)
            ->forUser($user)
            ->create();

        $response = $this->getJson('/api/v1/promotions');
        $welcomeRow = collect($response->json('data'))->firstWhere('code', 'WELCOME10');

        expect($welcomeRow['user_redemptions_remaining'])->toBeNull();
    });
});

describe('PromotionResource — authenticated context (regression: ensure the field works when called from /validate)', function (): void {
    /*
     * The /promotions list endpoint cannot exercise the authenticated
     * code path — /validate can. The validation response does not
     * embed a PromotionResource, so we exercise the resource directly
     * via a synthetic request to lock the auth branch coverage.
     */

    it('exposes per-user remaining redemptions when called against an authenticated request', function (): void {
        $user = User::factory()->customer()->create();
        $welcome = Promotion::query()->where('code', 'WELCOME10')->firstOrFail();
        PromotionRedemption::factory()->forPromotion($welcome)->forUser($user)->create();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $resource = new PromotionResource($welcome);
        $payload = $resource->toArray($request);

        // WELCOME10 per_user_limit = 1, one redemption → 0 remaining.
        expect($payload['user_redemptions_remaining'])->toBe(0);
    });

    it('clamps to 0 (never negative) when redemptions exceed per_user_limit', function (): void {
        $user = User::factory()->customer()->create();
        $welcome = Promotion::query()->where('code', 'WELCOME10')->firstOrFail();
        PromotionRedemption::factory()->count(2)->forPromotion($welcome)->forUser($user)->create();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $resource = new PromotionResource($welcome);
        $payload = $resource->toArray($request);

        expect($payload['user_redemptions_remaining'])->toBe(0);
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual responses
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/promotions — bilingual', function (): void {
    it('returns English names by default', function (): void {
        $response = $this->getJson('/api/v1/promotions');

        $welcome = collect($response->json('data'))->firstWhere('code', 'WELCOME10');
        expect($welcome['name'])->toBe('Welcome 10% Off');
        expect($welcome['description'])->toBe('Get 10% off your first purchase, up to AED 100.');
    });

    it('returns Arabic names when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/promotions', ['Accept-Language' => 'ar']);

        $welcome = collect($response->json('data'))->firstWhere('code', 'WELCOME10');
        expect($welcome['name'])->toBe('خصم ترحيبي 10%');
        expect($welcome['description'])->toBe('احصل على خصم 10% على أول عملية شراء، حتى 100 درهم.');
    });

    it('returns the localized success message in Arabic when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/promotions', ['Accept-Language' => 'ar']);

        expect($response->json('message'))->toBe('تم استرجاع العروض.');
    });
});

/*
|--------------------------------------------------------------------------
| Money & type fields
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/promotions — money formatting', function (): void {
    it('casts decimal money columns into numeric values', function (): void {
        // JSON decoders emit ints for whole numbers — use loose
        // equality so 10 == 10.0 holds. The resource always casts
        // through (float) before serialisation; the wire shape is
        // still a JSON number, with no leading zeros / trailing dots.
        $response = $this->getJson('/api/v1/promotions');

        $welcome = collect($response->json('data'))->firstWhere('code', 'WELCOME10');
        expect((float) $welcome['value'])->toBe(10.0)
            ->and((float) $welcome['max_discount_amount'])->toBe(100.0)
            ->and($welcome['min_order_amount'])->toBeNull()
            // Percent promos are currency-agnostic.
            ->and($welcome['currency'])->toBeNull()
            ->and($welcome['type'])->toBe('percent');

        $summer = collect($response->json('data'))->firstWhere('code', 'SUMMER50');
        expect((float) $summer['value'])->toBe(50.0)
            ->and((float) $summer['min_order_amount'])->toBe(500.0)
            ->and($summer['currency'])->toBe('AED')
            ->and($summer['type'])->toBe('fixed')
            // Fixed promos don't carry a max-cap.
            ->and($summer['max_discount_amount'])->toBeNull();
    });
});
