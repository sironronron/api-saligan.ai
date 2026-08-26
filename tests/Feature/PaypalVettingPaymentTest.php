<?php

use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Jobs\MatchVettingRequest;
use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use App\Services\Billing\VettingPaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->submitter = User::factory()->create();
});

function usePaypalVettingGateway(): void
{
    config([
        'vetting.payment_gateway' => 'paypal',
        'app.url' => 'https://api.test',
        'app.frontend_url' => 'https://app.test',
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'paypal.oauth_url' => 'https://api-m.sandbox.paypal.com/v1/oauth2/token',
        'paypal.client_id' => 'client-id',
        'paypal.client_secret' => 'client-secret',
    ]);

    Cache::flush();
}

function fakePaypalPaymentApi(array $additionalRoutes = []): void
{
    Http::fake(array_merge([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-123',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-123'],
            ],
        ]),
        'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123/authorize' => Http::response([
            'purchase_units' => [
                '0' => [
                    'payments' => [
                        'authorizations' => [['id' => 'AUTH-123']],
                    ],
                ],
            ],
        ]),
    ], $additionalRoutes));
}

function paidPaypalRequest(User $submitter): VettingRequest
{
    return VettingRequest::factory()->for($submitter, 'submitter')->create([
        'status' => VettingRequestStatus::PaymentPending,
        'vetting_fee' => 10000,
        'notarization_fee' => null,
        'processing_fee' => 0,
        'payment_status' => VettingPaymentStatus::None,
    ]);
}

it('creates a PayPal authorization order for a new vetting payment', function () {
    usePaypalVettingGateway();
    fakePaypalPaymentApi();

    $request = paidPaypalRequest($this->submitter);
    $checkout = app(VettingPaymentService::class)->authorize($request, $this->submitter);

    expect($checkout)->toBe([
        'checkout_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-123',
        'payment_intent_id' => 'ORDER-123',
    ]);

    $this->assertDatabaseHas('vetting_requests', [
        'id' => $request->id,
        'gateway_payment_intent_id' => 'ORDER-123',
        'gateway_checkout_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-123',
    ]);

    $this->assertDatabaseHas('vetting_payments', [
        'vetting_request_id' => $request->id,
        'gateway' => 'paypal',
        'gateway_payment_intent_id' => 'ORDER-123',
        'status' => 'pending',
    ]);

    Http::assertSent(fn ($httpRequest) => $httpRequest->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
        && data_get($httpRequest->data(), 'intent') === 'AUTHORIZE'
        && data_get($httpRequest->data(), 'purchase_units.0.custom_id') === $request->id
        && data_get($httpRequest->data(), 'purchase_units.0.amount.value') === '100.00');
});

it('authorizes an approved PayPal order once and starts matching', function () {
    usePaypalVettingGateway();
    Queue::fake();
    fakePaypalPaymentApi();

    $request = paidPaypalRequest($this->submitter);
    app(VettingPaymentService::class)->authorize($request, $this->submitter);

    $service = app(VettingPaymentService::class);
    expect($service->authorizePaypalOrder('ORDER-123')?->id)->toBe($request->id);

    $payment = $request->fresh()->payments()->first();

    expect($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Authorized)
        ->and($payment->status)->toBe(VettingPaymentStatus::Authorized)
        ->and($payment->gateway_payment_id)->toBe('AUTH-123')
        ->and($payment->metadata['authorization_id'])->toBe('AUTH-123');

    Queue::assertPushed(MatchVettingRequest::class);

    $service->authorizePaypalOrder('ORDER-123');

    expect(collect(Http::recorded())->filter(fn ($record) => str_ends_with($record[0]->url(), '/authorize')))
        ->toHaveCount(1);
});

it('captures an authorized PayPal payment and records the capture id', function () {
    usePaypalVettingGateway();
    fakePaypalPaymentApi([
        'https://api-m.sandbox.paypal.com/v2/payments/authorizations/AUTH-123/capture' => Http::response([
            'id' => 'CAP-123',
            'status' => 'COMPLETED',
        ]),
    ]);

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::UnderReview,
        'vetting_fee' => 10000,
        'notarization_fee' => null,
        'processing_fee' => 0,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'ORDER-123',
    ]);
    $payment = VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'gateway' => 'paypal',
        'status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'ORDER-123',
        'gateway_payment_id' => 'AUTH-123',
        'metadata' => ['authorization_id' => 'AUTH-123'],
    ]);

    expect(app(VettingPaymentService::class)->capture($request))->toBeTrue();

    expect($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Captured)
        ->and($payment->fresh()->status)->toBe(VettingPaymentStatus::Captured)
        ->and($payment->fresh()->metadata['capture_id'])->toBe('CAP-123');
});

it('voids an authorized PayPal payment when the request is cancelled', function () {
    usePaypalVettingGateway();
    fakePaypalPaymentApi([
        'https://api-m.sandbox.paypal.com/v2/payments/authorizations/AUTH-123/void' => Http::response([], 204),
    ]);

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
        'vetting_fee' => 10000,
        'notarization_fee' => null,
        'processing_fee' => 0,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'ORDER-123',
    ]);
    $payment = VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'gateway' => 'paypal',
        'status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'ORDER-123',
        'gateway_payment_id' => 'AUTH-123',
        'metadata' => ['authorization_id' => 'AUTH-123'],
    ]);

    app(VettingPaymentService::class)->refundOrVoid($request);

    expect($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Void)
        ->and($payment->fresh()->status)->toBe(VettingPaymentStatus::Void);
});

it('refunds a captured PayPal payment', function () {
    usePaypalVettingGateway();
    fakePaypalPaymentApi([
        'https://api-m.sandbox.paypal.com/v2/payments/captures/CAP-123/refund' => Http::response([
            'id' => 'REF-123',
            'status' => 'COMPLETED',
        ]),
    ]);

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
        'vetting_fee' => 10000,
        'notarization_fee' => null,
        'processing_fee' => 0,
        'payment_status' => VettingPaymentStatus::Captured,
        'gateway_payment_intent_id' => 'ORDER-123',
    ]);
    $payment = VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'gateway' => 'paypal',
        'status' => VettingPaymentStatus::Captured,
        'gateway_payment_intent_id' => 'ORDER-123',
        'gateway_payment_id' => 'AUTH-123',
        'metadata' => ['capture_id' => 'CAP-123'],
    ]);

    app(VettingPaymentService::class)->refundOrVoid($request);

    expect($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Refunded)
        ->and($payment->fresh()->status)->toBe(VettingPaymentStatus::Refunded)
        ->and($payment->fresh()->gateway_refund_id)->toBe('REF-123');
});

it('keeps persisted PayMongo payments on PayMongo for capture', function () {
    usePaypalVettingGateway();
    Http::fake([
        'api.paymongo.com/v1/payment_intents/pi_test123/capture' => Http::response([
            'data' => ['id' => 'pi_test123'],
        ]),
    ]);

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::UnderReview,
        'vetting_fee' => 10000,
        'notarization_fee' => null,
        'processing_fee' => 0,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);
    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'gateway' => 'paymongo',
        'status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    expect(app(VettingPaymentService::class)->capture($request))->toBeTrue();

    Http::assertSent(fn ($httpRequest) => str_contains($httpRequest->url(), '/v1/payment_intents/pi_test123/capture'));
});
