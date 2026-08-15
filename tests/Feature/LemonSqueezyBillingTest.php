<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingGatewayManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'billing.default_gateway' => 'lemonsqueezy',
        'lemonsqueezy.api_key' => 'test-key',
        'lemonsqueezy.base_url' => 'https://api.lemonsqueezy.com/v1',
    ]);

    $this->user = User::factory()->create();
    $this->standard = Plan::factory()->standard()->create();
    $this->pro = Plan::factory()->pro()->create();
});

it('resolves to LemonSqueezy when it is the default and the plan has a variant', function () {
    $this->standard->update(['lemonsqueezy_variant_id' => 123]);

    $manager = app(BillingGatewayManager::class);

    expect($manager->resolve($this->standard, Plan::INTERVAL_MONTHLY)->name()->value)
        ->toBe('lemonsqueezy');
});

it('falls back to PayMongo when the plan has no LemonSqueezy variant', function () {
    $manager = app(BillingGatewayManager::class);

    expect($manager->resolve($this->standard, Plan::INTERVAL_MONTHLY)->name()->value)
        ->toBe('paymongo');
});

it('uses PayMongo when it is the configured default even if variants exist', function () {
    config(['billing.default_gateway' => 'paymongo']);
    $this->standard->update(['lemonsqueezy_variant_id' => 123]);

    $manager = app(BillingGatewayManager::class);

    expect($manager->resolve($this->standard, Plan::INTERVAL_MONTHLY)->name()->value)
        ->toBe('paymongo');
});

it('starts a LemonSqueezy checkout and returns the hosted checkout url', function () {
    $this->pro->update(['lemonsqueezy_variant_id' => 456]);

    Http::fake([
        'api.lemonsqueezy.com/*' => Http::response([
            'data' => [
                'type' => 'checkouts',
                'id' => 'chk_test123',
                'attributes' => [
                    'checkout_url' => 'https://store.lemonsqueezy.com/checkout/buy/abc-123',
                ],
            ],
        ]),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertCreated();

    expect($response->json('checkout.checkout_url'))->toBe('https://store.lemonsqueezy.com/checkout/buy/abc-123')
        ->and($response->json('data.gateway'))->toBe('lemonsqueezy')
        ->and($response->json('data.status'))->toBe(Subscription::STATUS_INCOMPLETE);

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->pro->id,
        'gateway' => 'lemonsqueezy',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/checkouts')
            && data_get($request->data(), 'data.attributes.checkout_data.email') === $this->user->email
            && data_get($request->data(), 'data.attributes.checkout_data.custom.user_id') === $this->user->id
            && data_get($request->data(), 'data.relationships.variant.data.id') === '456';
    });
});

it('falls back to PayMongo at the API level when the plan has no LemonSqueezy variant', function () {
    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::response([
            'data' => ['id' => 'cus_test123', 'type' => 'customer', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_test123', 'type' => 'plan', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions' => Http::response([
            'data' => [
                'id' => 'subs_test123',
                'type' => 'subscription',
                'attributes' => [
                    'latest_invoice' => [
                        'payment_intent' => [
                            'id' => 'pi_test123',
                            'attributes' => ['client_key' => 'pi_test123_ck'],
                        ],
                    ],
                ],
            ],
        ]),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test123',
                'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test123#token'],
            ],
        ]),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertCreated();

    expect($response->json('checkout.checkout_url'))->toBe('https://checkout.paymongo.com/cs_test123#token')
        ->and($response->json('data.gateway'))->toBe('paymongo');

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'api.lemonsqueezy.com');
    });
});

it('changes plan through LemonSqueezy', function () {
    $this->standard->update(['lemonsqueezy_variant_id' => 100]);
    $this->pro->update(['lemonsqueezy_variant_id' => 200]);

    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'interval' => Plan::INTERVAL_MONTHLY,
        'gateway' => 'lemonsqueezy',
        'lemonsqueezy_subscription_id' => 'ls_sub_123',
    ]);

    Http::fake(['api.lemonsqueezy.com/*' => Http::response(['data' => []])]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk();

    expect(Subscription::firstWhere('user_id', $this->user->id)->plan_id)->toBe($this->pro->id);

    Http::assertSent(function ($request) {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), '/subscriptions/ls_sub_123')
            && data_get($request->data(), 'data.attributes.variant_id') === 200;
    });
});

it('cancels a LemonSqueezy subscription', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->pro->id,
        'gateway' => 'lemonsqueezy',
        'lemonsqueezy_subscription_id' => 'ls_sub_123',
    ]);

    Http::fake(['api.lemonsqueezy.com/*' => Http::response(['data' => []])]);

    $this->signInAs($this->user)->postJson('/api/subscription/cancel')->assertOk();

    $subscription = Subscription::firstWhere('user_id', $this->user->id);

    expect($subscription->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($subscription->cancelled_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/subscriptions/ls_sub_123'));
});

it('creates and activates a subscription from a LemonSqueezy webhook', function () {
    $this->standard->update(['lemonsqueezy_variant_id' => 123]);

    config(['lemonsqueezy.webhook_secret' => 'test-secret']);

    $payload = [
        'meta' => ['event_name' => 'subscription_activated'],
        'data' => [
            'type' => 'subscriptions',
            'id' => 'ls_sub_123',
            'attributes' => [
                'store_id' => 1,
                'customer_id' => 77,
                'variant_id' => 123,
                'status' => 'active',
                'user_email' => $this->user->email,
                'created_at' => '2026-08-07 00:00:00',
                'renews_at' => '2026-09-07 00:00:00',
            ],
        ],
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook/lemonsqueezy', $payload, [
        'X-Signature' => $signature,
    ])->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->standard->id,
        'interval' => Plan::INTERVAL_MONTHLY,
        'gateway' => 'lemonsqueezy',
        'lemonsqueezy_subscription_id' => 'ls_sub_123',
        'lemonsqueezy_customer_id' => 77,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    $subscription = Subscription::firstWhere('lemonsqueezy_subscription_id', 'ls_sub_123');

    expect($subscription->current_period_end->format('Y-m-d'))->toBe('2026-09-07');
});

it('maps an annual variant to an annual subscription on webhook', function () {
    $this->standard->update(['lemonsqueezy_variant_id_annual' => 124]);

    config(['lemonsqueezy.webhook_secret' => 'test-secret']);

    $payload = [
        'meta' => ['event_name' => 'subscription_payment_success'],
        'data' => [
            'type' => 'subscriptions',
            'id' => 'ls_sub_annual',
            'attributes' => [
                'customer_id' => 77,
                'variant_id' => 124,
                'status' => 'active',
                'user_email' => $this->user->email,
                'created_at' => '2026-08-07 00:00:00',
                'renews_at' => '2027-08-07 00:00:00',
            ],
        ],
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook/lemonsqueezy', $payload, [
        'X-Signature' => $signature,
    ])->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->standard->id,
        'interval' => Plan::INTERVAL_ANNUAL,
        'status' => Subscription::STATUS_ACTIVE,
    ]);
});

it('syncs a cancelled LemonSqueezy subscription', function () {
    $this->standard->update(['lemonsqueezy_variant_id' => 123]);

    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => 'lemonsqueezy',
        'lemonsqueezy_subscription_id' => 'ls_sub_123',
    ]);

    config(['lemonsqueezy.webhook_secret' => 'test-secret']);

    $payload = [
        'meta' => ['event_name' => 'subscription_cancelled'],
        'data' => [
            'type' => 'subscriptions',
            'id' => 'ls_sub_123',
            'attributes' => [
                'variant_id' => 123,
                'status' => 'cancelled',
                'user_email' => $this->user->email,
                'cancelled_at' => '2026-08-07 00:00:00',
            ],
        ],
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook/lemonsqueezy', $payload, [
        'X-Signature' => $signature,
    ])->assertOk();

    $subscription = Subscription::firstWhere('lemonsqueezy_subscription_id', 'ls_sub_123');

    expect($subscription->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($subscription->cancelled_at)->not->toBeNull();
});

it('rejects LemonSqueezy webhooks with an invalid signature', function () {
    config(['lemonsqueezy.webhook_secret' => 'test-secret']);

    $this->postJson('/api/subscriptions/webhook/lemonsqueezy', [
        'meta' => ['event_name' => 'subscription_activated'],
        'data' => ['type' => 'subscriptions', 'id' => '1', 'attributes' => []],
    ], [
        'X-Signature' => 'not-a-real-signature',
    ])->assertStatus(400);
});
