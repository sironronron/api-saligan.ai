<?php

use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Jobs\MatchVettingRequest;
use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->submitter = User::factory()->create();

    config([
        'app.frontend_url' => 'https://app.test',
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'paypal.oauth_url' => 'https://api-m.sandbox.paypal.com/v1/oauth2/token',
        'paypal.client_id' => 'client-id',
        'paypal.client_secret' => 'client-secret',
        'paypal.webhook_id' => 'webhook-id',
    ]);

    Cache::flush();
});

function pendingPaypalVettingPayment(User $submitter): array
{
    $request = VettingRequest::factory()->for($submitter, 'submitter')->create([
        'status' => VettingRequestStatus::PaymentPending,
        'vetting_fee' => 10000,
        'notarization_fee' => null,
        'processing_fee' => 0,
        'payment_status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'ORDER-123',
    ]);

    $payment = VettingPayment::factory()->for($request)->create([
        'submitter_id' => $submitter->id,
        'gateway' => 'paypal',
        'status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'ORDER-123',
    ]);

    return [$request, $payment];
}

function fakePaypalReturnAuthorization(): void
{
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123/authorize' => Http::response([
            'purchase_units' => [
                ['payments' => ['authorizations' => [['id' => 'AUTH-123']]]],
            ],
        ]),
    ]);
}

function fakeVettingPaypalWebhookVerification(): void
{
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => 'SUCCESS',
        ]),
    ]);
}

function vettingPaypalWebhookHeaders(): array
{
    return [
        'Paypal-Auth-Algo' => 'SHA256withRSA',
        'Paypal-Cert-Url' => 'https://api-m.sandbox.paypal.com/cert',
        'Paypal-Transmission-Id' => 'transmission-id',
        'Paypal-Transmission-Sig' => 'transmission-signature',
        'Paypal-Transmission-Time' => '2026-08-26T00:00:00Z',
    ];
}

it('authorizes a PayPal vetting order from the browser return and redirects back to the request', function () {
    Queue::fake();
    [$request] = pendingPaypalVettingPayment($this->submitter);
    fakePaypalReturnAuthorization();

    $this->get('/api/paypal/vetting/return?token=ORDER-123')
        ->assertRedirect("https://app.test/vetting/{$request->id}?payment=return");

    expect($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Authorized)
        ->and($request->fresh()->payments()->first()->gateway_payment_id)->toBe('AUTH-123');

    Queue::assertPushed(MatchVettingRequest::class);
});

it('redirects a cancelled PayPal vetting checkout without changing the pending payment', function () {
    [$request] = pendingPaypalVettingPayment($this->submitter);

    $this->get("/api/paypal/vetting/cancel?request={$request->id}")
        ->assertRedirect("https://app.test/vetting/{$request->id}?payment=cancelled");

    expect($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Pending);
});

it('rejects an unknown PayPal vetting return token', function () {
    $this->get('/api/paypal/vetting/return?token=ORDER-UNKNOWN')
        ->assertNotFound();
});

it('records PayPal capture and refund webhook events for a vetting payment', function () {
    [$request, $payment] = pendingPaypalVettingPayment($this->submitter);
    $request->update(['status' => VettingRequestStatus::UnderReview, 'payment_status' => VettingPaymentStatus::Authorized]);
    $payment->update([
        'status' => VettingPaymentStatus::Authorized,
        'gateway_payment_id' => 'AUTH-123',
        'metadata' => ['authorization_id' => 'AUTH-123'],
    ]);

    fakeVettingPaypalWebhookVerification();

    $capture = [
        'id' => 'CAP-123',
        'status' => 'COMPLETED',
        'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-123']],
    ];

    $this->postJson('/api/paypal/webhook', [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => $capture,
    ], vettingPaypalWebhookHeaders())->assertOk();

    expect($payment->fresh()->status)->toBe(VettingPaymentStatus::Captured)
        ->and($payment->fresh()->metadata['capture_id'])->toBe('CAP-123')
        ->and($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Captured);

    fakeVettingPaypalWebhookVerification();

    $this->postJson('/api/paypal/webhook', [
        'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
        'resource' => [
            'id' => 'REF-123',
            'status' => 'COMPLETED',
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-123']],
        ],
    ], vettingPaypalWebhookHeaders())->assertOk();

    expect($payment->fresh()->status)->toBe(VettingPaymentStatus::Refunded)
        ->and($payment->fresh()->gateway_refund_id)->toBe('REF-123')
        ->and($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Refunded);
});
