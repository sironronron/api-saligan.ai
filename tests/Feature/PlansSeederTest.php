<?php

use App\Models\Plan;
use App\Services\Billing\EarningsModel;
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

    expect(Plan::count())->toBe(3);

    foreach (Plan::all() as $plan) {
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

    expect(Plan::count())->toBe(3);

    foreach (Plan::all() as $plan) {
        expect($plan->paymongo_plan_id)->toBeNull()
            ->and($plan->paymongo_plan_id_annual)->toBeNull();
    }

    Http::assertNothingSent();
});

it('keeps existing PayMongo plan ids', function () {
    Plan::factory()->starter()->create([
        'paymongo_plan_id' => 'plan_starter',
        'paymongo_plan_id_annual' => 'plan_starter_annual',
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

    $starter = Plan::where('slug', Plan::SLUG_STARTER)->firstOrFail();
    $pro = Plan::where('slug', Plan::SLUG_PRO)->firstOrFail();
    $firm = Plan::where('slug', Plan::SLUG_FIRM)->firstOrFail();

    expect($starter->price)->toBe(150000)
        ->and($starter->price_annual)->toBe(1494000)
        ->and($starter->overage_price)->toBeNull()
        ->and($starter->limits['messages_used'])->toBe(120)
        ->and($pro->price)->toBe(350000)
        ->and($pro->price_annual)->toBe(3490000)
        ->and($pro->overage_price)->toBe(900)
        ->and($pro->limits['messages_used'])->toBe(300)
        ->and($firm->price)->toBe(1100000)
        ->and($firm->price_annual)->toBe(10990000)
        ->and($firm->overage_price)->toBe(850)
        ->and($firm->limits['messages_used'])->toBe(1000);
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

    foreach (Plan::all() as $plan) {
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

    expect(Plan::count())->toBe(3);

    foreach (Plan::all() as $plan) {
        expect($plan->paymongo_plan_id)->toBeNull();
    }
});
