<?php

use App\Models\Plan;
use App\Services\Billing\EarningsModel;
use App\Support\PlanFeatures;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seeder = new PlansSeeder;
});

it('provisions monthly and annual PayMongo plans and persists their ids', function () {
    Http::fake([
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_provisioned_'.fake()->word(), 'type' => 'plan', 'attributes' => []],
        ]),
    ]);

    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    expect(Plan::count())->toBe(5);

    foreach (Plan::where('price', '>', 0)->get() as $plan) {
        expect($plan->paymongo_plan_id)->not->toBeNull()
            ->and($plan->paymongo_plan_id_annual)->not->toBeNull();
    }

    Http::assertSentCount(6);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/subscriptions/plans')
            && data_get($request->data(), 'data.attributes.plan_type') === 'scheduled'
            && data_get($request->data(), 'data.attributes.interval') === 'monthly';
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/subscriptions/plans')
            && data_get($request->data(), 'data.attributes.interval') === 'yearly'
            && data_get($request->data(), 'data.attributes.amount') === 3490000;
    });
});

it('skips PayMongo provisioning when no secret key is configured', function () {
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    expect(Plan::count())->toBe(5);

    foreach (Plan::all() as $plan) {
        expect($plan->paymongo_plan_id)->toBeNull()
            ->and($plan->paymongo_plan_id_annual)->toBeNull();
    }

    Http::assertNothingSent();
});

it('keeps existing PayMongo plan ids', function () {
    Plan::factory()->standard()->create([
        'paymongo_plan_id' => 'plan_standard',
        'paymongo_plan_id_annual' => 'plan_standard_annual',
    ]);
    $pro = Plan::factory()->pro()->create([
        'paymongo_plan_id' => 'plan_existing',
        'paymongo_plan_id_annual' => 'plan_existing_annual',
    ]);
    Plan::factory()->firm()->create([
        'paymongo_plan_id' => 'plan_firm',
        'paymongo_plan_id_annual' => 'plan_firm_annual',
    ]);
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    expect($pro->fresh()->paymongo_plan_id)->toBe('plan_existing')
        ->and($pro->fresh()->paymongo_plan_id_annual)->toBe('plan_existing_annual');

    Http::assertNothingSent();
});

it('seeds the new pricing, caps, and overage rates', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $standard = Plan::where('slug', Plan::SLUG_STANDARD)->firstOrFail();
    $pro = Plan::where('slug', Plan::SLUG_PRO)->firstOrFail();
    $firm = Plan::where('slug', Plan::SLUG_FIRM)->firstOrFail();

    expect($standard->price)->toBe(150000)
        ->and($standard->price_annual)->toBe(1494000)
        ->and($standard->overage_price)->toBeNull()
        ->and($standard->limits['messages_used'])->toBe(240)
        ->and($pro->price)->toBe(350000)
        ->and($pro->price_annual)->toBe(3490000)
        ->and($pro->overage_price)->toBe(900)
        ->and($pro->limits['messages_used'])->toBe(300)
        ->and($firm->price)->toBe(1100000)
        ->and($firm->price_annual)->toBe(10990000)
        ->and($firm->overage_price)->toBe(850)
        // Per seat, not shared: allowances are counted per user, so Firm's
        // ₱11,000 covers three of these, not one pool of 300.
        ->and($firm->limits['messages_used'])->toBe(300)
        ->and($firm->included_seats)->toBe(3)
        ->and($firm->seat_price)->toBe(320000)
        // The single-seat tiers sell no seats at all, which is a different
        // statement from selling them for nothing.
        ->and($standard->included_seats)->toBe(1)
        ->and($standard->seat_price)->toBeNull()
        ->and($pro->seat_price)->toBeNull();
});

it('seeds the free trial plan at a quarter of Standard, unsold', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    $trial = Plan::where('slug', Plan::SLUG_TRIAL)->firstOrFail();
    $standard = Plan::where('slug', Plan::SLUG_STANDARD)->firstOrFail();

    foreach (['active_cases', 'documents_uploaded', 'messages_used'] as $key) {
        expect($trial->limits[$key])->toBe((int) ceil($standard->limits[$key] / 4));
    }

    // Free and hidden: it must never reach the pricing page, checkout, or a
    // gateway plan.
    expect($trial->price)->toBe(0)
        ->and($trial->is_active)->toBeFalse()
        ->and($trial->paymongo_plan_id)->toBeNull()
        ->and($trial->paymongo_plan_id_annual)->toBeNull()
        ->and($trial->features)->toBe($standard->features);
});

it('seeds the Business plan as listed but not self-serve', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    $business = Plan::where('slug', Plan::SLUG_BUSINESS)->firstOrFail();

    // Listed so it can be asked for, contact-only so it can never be bought,
    // and with no gateway plan behind it because nothing is charged here.
    expect($business->is_active)->toBeTrue()
        ->and($business->contact_sales)->toBeTrue()
        ->and($business->isSelfServe())->toBeFalse()
        ->and($business->price)->toBe(0)
        ->and($business->priceLabel())->toBe('Custom')
        ->and($business->priceAnnualLabel())->toBe('Custom')
        ->and($business->paymongo_plan_id)->toBeNull()
        ->and($business->paymongo_plan_id_annual)->toBeNull()
        ->and($business->limits['messages_used'])->toBeNull()
        ->and($business->limits['active_cases'])->toBeNull()
        ->and($business->limits['documents_uploaded'])->toBeNull();
});

it('sells the Business plan on what only a contract can carry', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $business = Plan::where('slug', Plan::SLUG_BUSINESS)->firstOrFail();
    $firm = Plan::where('slug', Plan::SLUG_FIRM)->firstOrFail();

    expect($business->features)->toContain(
        PlanFeatures::GUIDED_SETUP,
        PlanFeatures::TEAM_TRAINING,
        PlanFeatures::SUPPORT_24_7,
    )
        // There is one support promise, and Firm already has it. What Business
        // adds over Firm is the setup and training a contract pays for, not a
        // better grade of the same thing.
        ->and($firm->features)->toContain(PlanFeatures::SUPPORT_24_7);

    // Everything the tier below it has, plus what only a contract can carry.
    foreach ($firm->features as $feature) {
        expect($business->features)->toContain($feature);
    }
});

it('advertises only features that some code path enforces', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    // The rule this whole rework exists to establish: a feature on a plan is
    // either a capability the application refuses without, or a service a
    // person delivers under contract. Anything else is a claim nobody can
    // check — which is what `expanded_capacity` and `unlimited_cases` were.
    $known = array_keys(PlanFeatures::catalogue());

    foreach (Plan::all() as $plan) {
        foreach ($plan->features ?? [] as $feature) {
            expect($feature)->toBeIn($known);
        }
    }
});

it('gives every paid tier a capability the one below it lacks', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    $ladder = Plan::whereIn('slug', [Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM, Plan::SLUG_BUSINESS])
        ->orderBy('sort_order')
        ->get();

    // A tier that adds only numbers is a tier nobody can be told the reason
    // for. Every step up the ladder must unlock something the step below
    // genuinely cannot do.
    for ($index = 1; $index < $ladder->count(); $index++) {
        $plan = $ladder[$index];
        $below = $ladder[$index - 1];

        expect(array_diff($plan->features, $below->features))->not->toBeEmpty(
            "{$plan->name} adds no capability over {$below->name}.",
        );
    }
});

it('leaves the self-serve plans buyable', function () {
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    foreach ([Plan::SLUG_STANDARD, Plan::SLUG_PRO, Plan::SLUG_FIRM] as $slug) {
        expect(Plan::where('slug', $slug)->firstOrFail()->isSelfServe())->toBeTrue();
    }
});

it('prices every tier above what its messages cost to serve', function () {
    // The guardrail the old ladder failed: message cost is flat, so a tier
    // whose price divided by its cap falls under the marginal cost loses money
    // on its own allowance no matter how the plan is sold.
    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);
    config(['paymongo.secret_key' => '']);

    $this->seeder->run();

    // Costed at a pessimistic cold cache, so the floor holds even before the
    // prompt cache warms up.
    $costPerMessage = EarningsModel::perMessageCostPesos(
        'claude-sonnet-5',
        exchangeRate: 57.0,
        cached: true,
        cacheHitRate: 0.0,
    );

    // Paid tiers only: the free trial plan carries no revenue to cost against,
    // and its allowance is deliberately a loss leader.
    foreach (Plan::where('price', '>', 0)->get() as $plan) {
        $revenuePerMessage = $plan->price / 100 / $plan->limits['messages_used'];

        expect($revenuePerMessage)->toBeGreaterThan($costPerMessage);
    }
});

it('logs a warning and continues when PayMongo provisioning fails', function () {
    Http::fake(['api.paymongo.com/*' => Http::response([
        'errors' => [['code' => 'payment_method_not_configured', 'detail' => 'no subscription payment methods are configured']],
    ], 403)]);

    config(['paymongo.secret_key' => 'sk_test_123']);

    $this->seeder->run();

    expect(Plan::count())->toBe(5);

    foreach (Plan::all() as $plan) {
        expect($plan->paymongo_plan_id)->toBeNull();
    }
});
