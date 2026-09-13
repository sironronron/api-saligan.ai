<?php

use App\Models\Organization;
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

it('prevents organization members from starting shared billing', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->memberOf($organization)->create();

    $this->signInAs($member)
        ->postJson('/api/subscription', [
            'plan_id' => $this->pro->id,
            'billing_interval' => 'monthly',
        ])
        ->assertForbidden();
});

it('serializes initial checkout creation at organization scope', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->ownerOf($organization)->create();
    $lock = Cache::lock("subscription.checkout.organization.{$organization->id}", 30);
    $lock->get();

    try {
        $this->signInAs($owner)
            ->postJson('/api/subscription', [
                'plan_id' => $this->pro->id,
                'billing_interval' => 'monthly',
            ])
            ->assertConflict();
    } finally {
        $lock->forceRelease();
    }
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
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123/revise' => Http::response([
            'id' => 'I-SUB-123',
            'status' => 'ACTIVE',
            'links' => [
                [
                    'rel' => 'approve',
                    'href' => 'https://www.sandbox.paypal.com/billing/subscriptions/revise?token=I-SUB-123',
                ],
            ],
        ]),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk();

    expect($response->json('data.plan.slug'))->toBe('standard')
        ->and($response->json('checkout.checkout_url'))
        ->toBe('https://www.sandbox.paypal.com/billing/subscriptions/revise?token=I-SUB-123');

    expect($this->user->fresh()->subscription->plan_id)->toBe($this->standard->id);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123/revise'
        && data_get($request->data(), 'plan_id') === 'P-PRO-MONTHLY'
        && data_get($request->data(), 'application_context.return_url') === 'http://localhost:3000/settings/billing?paypal=plan-change-return&plan='.$this->pro->id
        && data_get($request->data(), 'application_context.cancel_url') === 'http://localhost:3000/settings/billing?paypal=plan-change-cancelled');
});

it('starts an annual PayPal plan change with the annual provider plan', function () {
    $subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'interval' => 'monthly',
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
    ]);

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123/revise' => Http::response([
            'id' => 'I-SUB-123',
            'status' => 'ACTIVE',
            'links' => [
                [
                    'rel' => 'approve',
                    'href' => 'https://www.sandbox.paypal.com/billing/subscriptions/revise?token=I-SUB-123',
                ],
            ],
        ]),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', [
            'plan_id' => $this->pro->id,
            'billing_interval' => 'annual',
        ])
        ->assertOk();

    expect($response->json('data.interval'))->toBe('monthly')
        ->and($response->json('data.pending_plan_id'))->toBe($this->pro->id)
        ->and($response->json('data.pending_plan_interval'))->toBe('annual')
        ->and($subscription->fresh()->interval)->toBe('monthly');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/billing/subscriptions/I-SUB-123/revise')
        && data_get($request->data(), 'plan_id') === 'P-PRO-ANNUAL');
});

it('prevents organization members from changing shared billing', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->memberOf($organization)->create();
    $subscription = Subscription::factory()->for($member)->create([
        'organization_id' => $organization->id,
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
    ]);

    $this->signInAs($member)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertForbidden();

    expect($subscription->fresh()->plan_id)->toBe($this->standard->id);
});

it('prevents a removed organization admin from managing shared billing', function () {
    $organization = Organization::factory()->create();
    $formerAdmin = User::factory()->ownerOf($organization)->create();
    $subscription = Subscription::factory()->for($formerAdmin)->create([
        'organization_id' => $organization->id,
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
    ]);

    $formerAdmin->update([
        'organization_id' => null,
        'org_role' => null,
        'org_status' => null,
    ]);

    $this->signInAs($formerAdmin)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertForbidden();

    $this->signInAs($formerAdmin)
        ->postJson('/api/subscription/cancel')
        ->assertForbidden();

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->fresh()->plan_id)->toBe($this->standard->id);
});

it('does not expose a shared PayPal approval URL to organization members', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->memberOf($organization)->create();
    $subscription = Subscription::factory()->for($member)->create([
        'organization_id' => $organization->id,
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'pending_plan_id' => $this->pro->id,
        'pending_plan_checkout_url' => 'https://www.sandbox.paypal.com/revise',
    ]);

    $this->signInAs($member)
        ->getJson('/api/subscription')
        ->assertOk()
        ->assertJsonPath('data.pending_plan_id', $this->pro->id)
        ->assertJsonPath('data.pending_plan_checkout_url', null);

    expect($subscription->fresh()->pending_plan_checkout_url)
        ->toBe('https://www.sandbox.paypal.com/revise');
});

it('prevents organization members from cancelling shared billing', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->memberOf($organization)->create();
    $subscription = Subscription::factory()->for($member)->create([
        'organization_id' => $organization->id,
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
    ]);

    $this->signInAs($member)
        ->postJson('/api/subscription/cancel')
        ->assertForbidden();

    expect($subscription->fresh()->status)->not->toBe(Subscription::STATUS_CANCELLED);
});

it('allows a personal subscription owner to manage billing after joining an organization', function () {
    $organization = Organization::factory()->create();
    $this->user->update([
        'organization_id' => $organization->id,
        'org_role' => User::ORG_ROLE_MEMBER,
        'org_status' => User::ORG_STATUS_ACTIVE,
    ]);
    $this->standard->update(['paymongo_plan_id' => 'plan_standard']);
    $this->pro->update(['paymongo_plan_id' => 'plan_pro']);
    $subscription = Subscription::factory()->for($this->user)->create([
        'organization_id' => null,
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYMONGO,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk()
        ->assertJsonPath('checkout', null);

    expect($subscription->fresh()->plan_id)->toBe($this->pro->id);
});

it('serializes cancellation with an in-flight plan change', function () {
    $subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'pending_plan_id' => $this->pro->id,
        'pending_plan_checkout_url' => 'https://www.sandbox.paypal.com/revise',
    ]);
    $lock = Cache::lock("subscription.plan-change.{$subscription->id}", 30);
    $lock->get();

    try {
        $this->signInAs($this->user)
            ->postJson('/api/subscription/cancel')
            ->assertConflict();
    } finally {
        $lock->forceRelease();
    }
});

it('rejects plan changes for subscriptions without a payment provider', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYMONGO,
        'status' => Subscription::STATUS_TRIALING,
        'paypal_subscription_id' => null,
        'paymongo_subscription_id' => null,
        'lemonsqueezy_subscription_id' => null,
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This subscription is not managed by a payment provider yet.');
});

it('does not apply a PayPal plan while approval is pending', function () {
    $subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'status' => Subscription::STATUS_ACTIVE,
        'pending_plan_id' => $this->pro->id,
        'pending_plan_checkout_url' => 'https://www.sandbox.paypal.com/revise',
    ]);

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => 'SUCCESS',
        ]),
    ]);

    $this->postJson('/api/paypal/webhook', [
        'id' => 'WH-APPROVAL-PENDING',
        'event_type' => 'BILLING.SUBSCRIPTION.UPDATED',
        'create_time' => '2026-08-26T12:00:00Z',
        'resource' => [
            'id' => 'I-SUB-123',
            'status' => 'APPROVAL_PENDING',
            'plan_id' => 'P-PRO-MONTHLY',
        ],
    ], [
        'Paypal-Auth-Algo' => 'SHA256withRSA',
        'Paypal-Cert-Url' => 'https://api.paypal.com/cert.pem',
        'Paypal-Transmission-Id' => 'transmission-id',
        'Paypal-Transmission-Sig' => 'transmission-signature',
        'Paypal-Transmission-Time' => '2026-08-26T12:00:00Z',
    ])->assertOk();

    expect($subscription->fresh()->plan_id)->toBe($this->standard->id)
        ->and($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->fresh()->pending_plan_id)->toBe($this->pro->id);
});

it('does not restore pending state when PayPal approves during revision creation', function () {
    $subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    $pendingWasSetBeforeRevision = false;

    Http::fake(function ($request) use ($subscription, &$pendingWasSetBeforeRevision) {
        if (str_contains($request->url(), '/v1/oauth2/token')) {
            return Http::response(['access_token' => 'access-token', 'expires_in' => 3600]);
        }

        if (str_ends_with($request->url(), '/v1/billing/subscriptions/I-SUB-123/revise')) {
            $current = $subscription->fresh();
            $pendingWasSetBeforeRevision = $current->pending_plan_id === $this->pro->id;
            $current->update([
                'plan_id' => $this->pro->id,
                'pending_plan_id' => null,
                'pending_plan_checkout_url' => null,
            ]);

            return Http::response([
                'id' => 'I-SUB-123',
                'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/revise']],
            ]);
        }

        return Http::response([], 404);
    });

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk()
        ->assertJsonPath('checkout', null);

    expect($pendingWasSetBeforeRevision)->toBeTrue()
        ->and($subscription->fresh()->plan_id)->toBe($this->pro->id)
        ->and($subscription->fresh()->pending_plan_id)->toBeNull();
});

it('reuses a pending PayPal plan change instead of creating another revision', function () {
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
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123/revise' => Http::response([
            'id' => 'I-SUB-123',
            'links' => [
                [
                    'rel' => 'approve',
                    'href' => 'https://www.sandbox.paypal.com/billing/subscriptions/revise?token=I-SUB-123',
                ],
            ],
        ]),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk();

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk()
        ->assertJsonPath('checkout.checkout_url', 'https://www.sandbox.paypal.com/billing/subscriptions/revise?token=I-SUB-123');

    expect(collect(Http::recorded())
        ->filter(fn ($record) => str_ends_with($record[0]->url(), '/v1/billing/subscriptions/I-SUB-123/revise')))
        ->toHaveCount(1);
});

it('clears a pending PayPal plan change when the buyer cancels approval', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'pending_plan_id' => $this->pro->id,
        'pending_plan_checkout_url' => 'https://www.sandbox.paypal.com/revise',
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan/cancel')
        ->assertOk();

    expect($this->user->fresh()->subscription->pending_plan_id)->toBeNull()
        ->and($this->user->fresh()->subscription->pending_plan_checkout_url)->toBeNull();
});

it('ignores a late PayPal webhook for a cancelled plan revision', function () {
    config([
        'paypal.plans.standard.monthly' => 'P-STANDARD-MONTHLY',
        'paypal.plans.pro.monthly' => 'P-PRO-MONTHLY',
    ]);

    $subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->standard->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'status' => Subscription::STATUS_ACTIVE,
        'pending_plan_id' => $this->pro->id,
        'pending_plan_checkout_url' => 'https://www.sandbox.paypal.com/revise',
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan/cancel')
        ->assertOk();

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => 'SUCCESS',
        ]),
    ]);

    $this->postJson('/api/paypal/webhook', [
        'id' => 'WH-LATE-REVISION',
        'event_type' => 'BILLING.SUBSCRIPTION.UPDATED',
        'create_time' => '2026-08-26T12:00:00Z',
        'resource' => [
            'id' => 'I-SUB-123',
            'status' => 'ACTIVE',
            'plan_id' => 'P-PRO-MONTHLY',
        ],
    ], [
        'Paypal-Auth-Algo' => 'SHA256withRSA',
        'Paypal-Cert-Url' => 'https://api.paypal.com/cert.pem',
        'Paypal-Transmission-Id' => 'transmission-id',
        'Paypal-Transmission-Sig' => 'transmission-signature',
        'Paypal-Transmission-Time' => '2026-08-26T12:00:00Z',
    ])->assertOk();

    expect($subscription->fresh()->plan_id)->toBe($this->standard->id)
        ->and($subscription->fresh()->pending_plan_id)->toBeNull();
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

it('does not resurrect a locally cancelled subscription from a late active webhook', function () {
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
        'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SUB-123/cancel' => Http::response([], 204),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription/cancel')
        ->assertOk();

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => 'SUCCESS',
        ]),
    ]);

    $this->postJson('/api/paypal/webhook', [
        'id' => 'WH-LATE-ACTIVE',
        'event_type' => 'BILLING.SUBSCRIPTION.UPDATED',
        'create_time' => '2026-08-26T12:00:00Z',
        'resource' => [
            'id' => 'I-SUB-123',
            'status' => 'ACTIVE',
            'plan_id' => 'P-STANDARD-MONTHLY',
        ],
    ], [
        'Paypal-Auth-Algo' => 'SHA256withRSA',
        'Paypal-Cert-Url' => 'https://api.paypal.com/cert.pem',
        'Paypal-Transmission-Id' => 'transmission-id',
        'Paypal-Transmission-Sig' => 'transmission-signature',
        'Paypal-Transmission-Time' => '2026-08-26T12:00:00Z',
    ])->assertOk();

    expect($this->user->fresh()->subscription->status)->toBe(Subscription::STATUS_CANCELLED);
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
