<?php

use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Models\LawyerProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use Illuminate\Support\Facades\Http;

function offerablePaymentLawyer(array $attributes = []): array
{
    $lawyer = User::factory()->create();

    $profile = LawyerProfile::factory()->notary()->for($lawyer)->create(array_merge([
        'practice_areas' => ['real_estate'],
        'region' => 'ncr',
    ], $attributes));

    return [$lawyer, $profile];
}

beforeEach(function () {
    $this->plan = Plan::factory()->pro()->create();
    $this->submitter = User::factory()->create();
    Subscription::factory()->for($this->submitter)->create(['plan_id' => $this->plan->id]);
});

it('authorizes the payment and starts matching when given a payment intent id', function () {
    [$lawyer] = offerablePaymentLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'service_type' => 'notarization',
        'status' => VettingRequestStatus::PaymentPending,
        'payment_status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_NOTARIZATION,
        'amount' => 50000,
        'status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    Http::fake([
        'api.paymongo.com/v1/payment_intents/pi_test123' => Http::response([
            'data' => [
                'id' => 'pi_test123',
                'type' => 'payment_intent',
                'attributes' => [
                    'status' => 'succeeded',
                    'payments' => [['id' => 'pay_test123', 'type' => 'payment']],
                ],
            ],
        ]),
    ]);

    $this->artisan('vetting:payment-process', ['payment-intent-id' => 'pi_test123'])->assertExitCode(0);

    $request->refresh();

    expect($request->payment_status)->toBe(VettingPaymentStatus::Authorized)
        ->and($request->status)->toBe(VettingRequestStatus::Matched);

    $this->assertDatabaseHas('vetting_payments', [
        'vetting_request_id' => $request->id,
        'status' => 'authorized',
    ]);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('resolves the intent when given a PayMongo payment id', function () {
    offerablePaymentLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'service_type' => 'vetting',
        'status' => VettingRequestStatus::PaymentPending,
        'payment_status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_VETTING,
        'status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    Http::fake([
        'api.paymongo.com/v1/payments/pay_test123' => Http::response([
            'data' => [
                'id' => 'pay_test123',
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'payment_intent_id' => 'pi_test123',
                ],
            ],
        ]),
    ]);

    $this->artisan('vetting:payment-process', ['payment-id' => 'pay_test123'])->assertExitCode(0);

    expect($request->refresh()->payment_status)->toBe(VettingPaymentStatus::Authorized);

    $this->assertDatabaseHas('vetting_payments', [
        'vetting_request_id' => $request->id,
        'status' => 'authorized',
        'gateway_payment_id' => 'pay_test123',
    ]);
});

it('authorizes a payment intent even when it carries no payment id', function () {
    [$lawyer] = offerablePaymentLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'service_type' => 'notarization',
        'status' => VettingRequestStatus::PaymentPending,
        'payment_status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_NOTARIZATION,
        'amount' => 50000,
        'status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    Http::fake([
        'api.paymongo.com/v1/payment_intents/pi_test123' => Http::response([
            'data' => [
                'id' => 'pi_test123',
                'type' => 'payment_intent',
                'attributes' => ['status' => 'succeeded'],
            ],
        ]),
    ]);

    $this->artisan('vetting:payment-process', ['payment-intent-id' => 'pi_test123'])->assertExitCode(0);

    $request->refresh();

    expect($request->payment_status)->toBe(VettingPaymentStatus::Authorized)
        ->and($request->status)->toBe(VettingRequestStatus::Matched);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('falls back to the single awaiting request when the gateway id matches nothing tracked', function () {
    offerablePaymentLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'service_type' => 'vetting',
        'status' => VettingRequestStatus::PaymentPending,
        'payment_status' => VettingPaymentStatus::None,
        'gateway_payment_intent_id' => 'pi_tracked',
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_VETTING,
        'status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_tracked',
    ]);

    // The payment belongs to an intent this environment has never seen.
    Http::fake([
        'api.paymongo.com/v1/payments/pay_untracked' => Http::response([
            'data' => [
                'id' => 'pay_untracked',
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'payment_intent_id' => 'pi_untracked',
                ],
            ],
        ]),
    ]);

    $this->artisan('vetting:payment-process', ['payment-id' => 'pay_untracked'])->assertExitCode(0);

    $request->refresh();

    expect($request->payment_status)->toBe(VettingPaymentStatus::Authorized)
        ->and($request->status)->toBe(VettingRequestStatus::Matched);

    $this->assertDatabaseHas('vetting_payments', [
        'vetting_request_id' => $request->id,
        'status' => 'authorized',
        'gateway_payment_id' => 'pay_untracked',
    ]);
});

it('reopens a declined request and starts matching when the payment is authorized', function () {
    [$lawyer] = offerablePaymentLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'service_type' => 'vetting',
        'status' => VettingRequestStatus::Declined,
        'payment_status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_VETTING,
        'status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    $this->artisan('vetting:payment-process', ['payment-intent-id' => 'pi_test123'])->assertExitCode(0);

    $request->refresh();

    expect($request->payment_status)->toBe(VettingPaymentStatus::Authorized)
        ->and($request->status)->toBe(VettingRequestStatus::Matched);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('stores the payment id on the payment row when both ids are given', function () {
    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'service_type' => 'vetting',
        'status' => VettingRequestStatus::PaymentPending,
        'payment_status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_VETTING,
        'status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    $this->artisan('vetting:payment-process', [
        'payment-intent-id' => 'pi_test123',
        'payment-id' => 'pay_test123',
    ])->assertExitCode(0);

    $this->assertDatabaseHas('vetting_payments', [
        'vetting_request_id' => $request->id,
        'status' => 'authorized',
        'gateway_payment_id' => 'pay_test123',
    ]);
});

it('refuses the fallback when several requests are awaiting payment', function () {
    VettingRequest::factory()->count(2)->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::PaymentPending,
        'payment_status' => VettingPaymentStatus::None,
        'gateway_payment_intent_id' => 'pi_tracked_a',
    ]);

    Http::fake([
        'api.paymongo.com/v1/payments/pay_untracked' => Http::response([
            'data' => [
                'id' => 'pay_untracked',
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'payment_intent_id' => 'pi_untracked',
                ],
            ],
        ]),
    ]);

    $this->artisan('vetting:payment-process', ['payment-id' => 'pay_untracked'])->assertExitCode(1);
});

it('fails when no request matches the payment intent', function () {
    Http::fake([
        'api.paymongo.com/v1/payments/pay_ghost' => Http::response([
            'data' => [
                'id' => 'pay_ghost',
                'type' => 'payment',
                'attributes' => [
                    'status' => 'paid',
                    'payment_intent_id' => 'pi_ghost',
                ],
            ],
        ]),
    ]);

    $this->artisan('vetting:payment-process', ['payment-id' => 'pay_ghost'])->assertExitCode(1);
});

it('is a no-op when the payment is already authorized', function () {
    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'service_type' => 'vetting',
        'status' => VettingRequestStatus::Matched,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    Http::fake([
        'api.paymongo.com/v1/payment_intents/pi_test123' => Http::response([
            'data' => ['id' => 'pi_test123', 'type' => 'payment_intent', 'attributes' => []],
        ]),
    ]);

    $this->artisan('vetting:payment-process', ['payment-intent-id' => 'pi_test123'])->assertExitCode(0);

    expect($request->refresh()->payment_status)->toBe(VettingPaymentStatus::Authorized);
});
