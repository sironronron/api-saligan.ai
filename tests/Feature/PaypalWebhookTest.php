<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->plan = Plan::factory()->standard()->create();
    $this->subscription = Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->plan->id,
        'gateway' => Subscription::GATEWAY_PAYPAL,
        'paypal_subscription_id' => 'I-SUB-123',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    config([
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'paypal.oauth_url' => 'https://api-m.sandbox.paypal.com/v1/oauth2/token',
        'paypal.client_id' => 'client-id',
        'paypal.client_secret' => 'client-secret',
        'paypal.webhook_id' => 'webhook-id',
    ]);

    Cache::flush();
});

function paypalWebhookHeaders(): array
{
    return [
        'Paypal-Auth-Algo' => 'SHA256withRSA',
        'Paypal-Cert-Url' => 'https://api.paypal.com/cert.pem',
        'Paypal-Transmission-Id' => 'transmission-id',
        'Paypal-Transmission-Sig' => 'transmission-signature',
        'Paypal-Transmission-Time' => '2026-08-26T12:00:00Z',
    ];
}

function fakePaypalWebhookVerification(bool $verified = true): void
{
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => $verified ? 'SUCCESS' : 'FAILURE',
        ]),
    ]);
}

function paypalSubscriptionEvent(string $eventType, array $resource = []): array
{
    return [
        'id' => 'WH-123',
        'event_type' => $eventType,
        'resource' => ['id' => 'I-SUB-123', ...$resource],
    ];
}

it('activates a PayPal subscription from a verified webhook', function () {
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.ACTIVATED', [
        'status' => 'ACTIVE',
        'start_time' => '2026-08-26T12:00:00Z',
        'billing_info' => ['next_billing_time' => '2026-09-26T12:00:00Z'],
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->current_period_start?->toDateString())->toBe('2026-08-26')
        ->and($subscription->current_period_end?->toDateString())->toBe('2026-09-26')
        ->and($subscription->cancelled_at)->toBeNull();
});

it('maps PayPal suspension and cancellation events to local statuses', function () {
    fakePaypalWebhookVerification();

    $this->postJson(
        '/api/paypal/webhook',
        paypalSubscriptionEvent('BILLING.SUBSCRIPTION.SUSPENDED', ['status' => 'SUSPENDED']),
        paypalWebhookHeaders(),
    )->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_PAUSED);

    $this->postJson(
        '/api/paypal/webhook',
        paypalSubscriptionEvent('BILLING.SUBSCRIPTION.CANCELLED', ['status' => 'CANCELLED']),
        paypalWebhookHeaders(),
    )->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($this->subscription->fresh()->cancelled_at)->not->toBeNull();
});

it('reactivates the local subscription after a PayPal renewal sale', function () {
    fakePaypalWebhookVerification();

    $this->subscription->update(['status' => Subscription::STATUS_PAST_DUE]);

    $payload = paypalSubscriptionEvent('PAYMENT.SALE.COMPLETED', [
        'billing_agreement_id' => 'I-SUB-123',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

it('accepts repeated PayPal webhook delivery without changing the result', function () {
    fakePaypalWebhookVerification();

    $payload = paypalSubscriptionEvent('BILLING.SUBSCRIPTION.ACTIVATED', [
        'status' => 'ACTIVE',
    ]);

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();
    $firstUpdatedAt = $this->subscription->fresh()->updated_at;

    $this->postJson('/api/paypal/webhook', $payload, paypalWebhookHeaders())->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($this->subscription->fresh()->updated_at->greaterThanOrEqualTo($firstUpdatedAt))->toBeTrue();
});

it('rejects a PayPal webhook when PayPal cannot verify it', function () {
    fakePaypalWebhookVerification(false);

    $this->postJson(
        '/api/paypal/webhook',
        paypalSubscriptionEvent('BILLING.SUBSCRIPTION.ACTIVATED'),
        paypalWebhookHeaders(),
    )->assertStatus(400);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_INCOMPLETE);
});
