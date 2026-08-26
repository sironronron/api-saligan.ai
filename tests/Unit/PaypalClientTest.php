<?php

use App\Services\Billing\PaypalClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        'paypal.client_id' => 'client-id',
        'paypal.client_secret' => 'client-secret',
        'paypal.oauth_url' => 'https://api-m.sandbox.paypal.com/v1/oauth2/token',
        'paypal.webhook_id' => 'webhook-id',
        'paypal.timeout' => 10,
    ]);

    Cache::flush();
});

it('caches its OAuth token and creates PHP authorization orders', function () {
    Http::fake([
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
        'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123' => Http::response([
            'id' => 'ORDER-123',
        ]),
    ]);

    $client = app(PaypalClient::class);

    $order = $client->createOrder(
        amount: 12500,
        description: 'Batayan document vetting',
        customId: 'request-123',
        returnUrl: 'https://api.test/api/paypal/vetting/return',
        cancelUrl: 'https://api.test/api/paypal/vetting/cancel',
    );

    $client->getOrder('ORDER-123');

    expect($order['id'])->toBe('ORDER-123');

    $recorded = collect(Http::recorded());
    expect($recorded->filter(fn ($record) => $record[0]->url() === 'https://api-m.sandbox.paypal.com/v1/oauth2/token'))
        ->toHaveCount(1);

    Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
        && $request->header('Authorization') === ['Basic '.base64_encode('client-id:client-secret')]
        && $request->data() === ['grant_type' => 'client_credentials']);

    Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
        && $request->header('Authorization') === ['Bearer access-token']
        && filled($request->header('PayPal-Request-Id'))
        && data_get($request->data(), 'intent') === 'AUTHORIZE'
        && data_get($request->data(), 'purchase_units.0.custom_id') === 'request-123'
        && data_get($request->data(), 'purchase_units.0.amount.currency_code') === 'PHP'
        && data_get($request->data(), 'purchase_units.0.amount.value') === '125.00');

    Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-123'
        && $request->header('Authorization') === ['Bearer access-token']);
});

it('sends unique request ids on every mutating operation', function () {
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        '*' => Http::response(['id' => 'provider-id']),
    ]);

    $client = app(PaypalClient::class);

    $client->createSubscription('P-123', 'subscription-123', 'https://api.test/return', 'https://api.test/cancel');
    $client->createOrder(12500, 'Document vetting', 'request-123', 'https://api.test/return', 'https://api.test/cancel');
    $client->authorizeOrder('ORDER-123');
    $client->captureOrder('ORDER-123');
    $client->voidAuthorization('AUTH-123');
    $client->refundCapture('CAPTURE-123');
    $client->patchSubscriptionPlan('SUB-123', 'P-456');
    $client->cancelSubscription('SUB-123');

    $requests = collect(Http::recorded())
        ->map(fn ($record) => $record[0])
        ->filter(fn ($request) => $request->url() !== 'https://api-m.sandbox.paypal.com/v1/oauth2/token');
    $requestIds = $requests->map(fn ($request) => $request->header('PayPal-Request-Id'));

    expect($requests)->toHaveCount(8)
        ->and($requestIds->filter()->unique())->toHaveCount(8);

    Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/SUB-123'
        && $request->data() === [
            ['op' => 'replace', 'path' => '/plan_id', 'value' => 'P-456'],
        ]);
});

it('posts PayPal transmission headers and the decoded event for verification', function () {
    $event = [
        'id' => 'WH-123',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => 'ORDER-123'],
    ];

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
            'verification_status' => 'SUCCESS',
        ]),
    ]);

    $verified = app(PaypalClient::class)->verifyWebhookSignature([
        'paypal-auth-algo' => ['SHA256withRSA'],
        'paypal-cert-url' => ['https://api.paypal.com/cert.pem'],
        'paypal-transmission-id' => ['transmission-id'],
        'paypal-transmission-sig' => ['transmission-signature'],
        'paypal-transmission-time' => ['2026-08-26T12:00:00Z'],
    ], json_encode($event));

    expect($verified)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature'
        && $request->header('Authorization') === ['Bearer access-token']
        && $request->data() == [
            'auth_algo' => 'SHA256withRSA',
            'cert_url' => 'https://api.paypal.com/cert.pem',
            'transmission_id' => 'transmission-id',
            'transmission_sig' => 'transmission-signature',
            'transmission_time' => '2026-08-26T12:00:00Z',
            'webhook_id' => 'webhook-id',
            'webhook_event' => $event,
        ]);
});

it('accepts successful PayPal mutations that return no response body', function () {
    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
            'access_token' => 'access-token',
            'expires_in' => 3600,
        ]),
        '*' => Http::response('', 204),
    ]);

    $client = app(PaypalClient::class);

    expect($client->voidAuthorization('AUTH-123'))->toBe([])
        ->and($client->patchSubscriptionPlan('SUB-123', 'P-456'))->toBe([])
        ->and($client->cancelSubscription('SUB-123'))->toBe([]);
});
