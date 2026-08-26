<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingGatewayManager;
use App\Services\Billing\PaymongoGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->standard = Plan::factory()->standard()->create();
    $this->pro = Plan::factory()->pro()->create();

    config([
        'billing.default_gateway' => 'paypal',
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'paypal.oauth_url' => 'https://api-m.sandbox.paypal.com/v1/oauth2/token',
        'paypal.client_id' => 'client-id',
        'paypal.client_secret' => 'client-secret',
        'paypal.plans.standard.monthly' => 'P-STANDARD-MONTHLY',
        'paypal.plans.pro.monthly' => 'P-PRO-MONTHLY',
        'paypal.plans.pro.annual' => 'P-PRO-ANNUAL',
    ]);

    Cache::flush();
});

function fakePaypalSubscriptionCheckout(string $subscriptionId = 'I-SUB-123'): void
{
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions' => Http::response([
            'id' => $subscriptionId,
            'status' => 'APPROVAL_PENDING',
            'links' => [
                [
                    'rel' => 'approve',
                    'href' => "https://www.sandbox.paypal.com/checkoutnow?token={$subscriptionId}",
                ],
            ],
        ]),
    ]);
}

it('starts a PayPal subscription and returns its approval URL', function () {
    fakePaypalSubscriptionCheckout();

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription', [
            'plan_id' => $this->pro->id,
            'billing_interval' => 'monthly',
        ])
        ->assertCreated();

    expect($response->json('data.status'))->toBe(Subscription::STATUS_INCOMPLETE)
        ->and($response->json('data.gateway'))->toBe('paypal')
        ->and($response->json('checkout.checkout_url'))
        ->toBe('https://www.sandbox.paypal.com/checkoutnow?token=I-SUB-123');

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->pro->id,
        'gateway' => 'paypal',
        'paypal_subscription_id' => 'I-SUB-123',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'paymongo'));
});

it('rejects PayPal checkout when the selected interval has no PayPal plan', function () {
    config(['paypal.plans.pro.annual' => '']);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', [
            'plan_id' => $this->pro->id,
            'billing_interval' => 'annual',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This plan is not configured for PayPal checkout.');

    $this->assertDatabaseCount('subscriptions', 0);
});

it('rejects a PayPal response without an approval link', function () {
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions' => Http::response([
            'id' => 'I-SUB-123',
            'links' => [],
        ]),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', [
            'plan_id' => $this->pro->id,
            'billing_interval' => 'monthly',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The PayPal checkout could not be initialized. Please try again.');

    $this->assertDatabaseCount('subscriptions', 0);
});

it('changes a PayPal subscription plan through PayPal', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
    ]);

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123' => Http::response([], 204),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk();

    expect($response->json('data.plan.slug'))->toBe('pro');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request->url() === 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123'
        && $request->data() === [
            ['op' => 'replace', 'path' => '/plan_id', 'value' => 'P-PRO-MONTHLY'],
        ]);
});

it('cancels a PayPal subscription through PayPal', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->pro->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
    ]);

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123/cancel' => Http::response([], 204),
    ]);

    $this->signInAs($this->user)->postJson('/api/subscription/cancel')->assertOk();

    expect($this->user->fresh()->subscription->status)->toBe(Subscription::STATUS_CANCELLED);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/billing/subscriptions/I-SUB-123/cancel'));
});

it('keeps existing PayMongo subscriptions on the PayMongo gateway', function () {
    $subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYMONGO,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    expect(app(BillingGatewayManager::class)->for($subscription))
        ->toBeInstanceOf(PaymongoGateway::class);
});
