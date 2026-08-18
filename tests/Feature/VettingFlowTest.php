<?php

use App\Enums\VettingMatchStatus;
use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Jobs\EscalateVettingRequest;
use App\Jobs\MatchWaitingRequests;
use App\Models\LawyerPayout;
use App\Models\LawyerProfile;
use App\Models\NotarialJournalEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;
use App\Models\VettingRequestMatch;
use App\Notifications\LawyerPayoutAvailable;
use App\Notifications\NewVettingRequest;
use App\Notifications\NotarizationScheduled;
use App\Notifications\VettingRequestAccepted;
use App\Notifications\VettingRequestCancelled;
use App\Notifications\VettingRequestStatusChanged;
use App\Notifications\VettingRequestWaiting;
use App\Services\Vetting\LawyerMatcher;
use App\Services\Vetting\VettingRequestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    // Free by default so the matching/workflow tests can skip the payment leg;
    // the payment tests set a fee explicitly.
    config(['vetting.default_vetting_fee' => 0, 'vetting.default_notarization_fee' => 0]);

    $this->plan = Plan::factory()->pro()->create();
    $this->submitter = User::factory()->create();
    Subscription::factory()->for($this->submitter)->create(['plan_id' => $this->plan->id]);

    $this->submitRequest = function (array $overrides = [], ?string $documentType = 'deed') {
        return $this->signInAs($this->submitter)->postJson('/api/vetting-requests', array_merge([
            'document_type' => $documentType,
            'summary' => 'A deed of sale for a Makati condo.',
            'jurisdiction' => 'ncr',
            'service_type' => 'vetting',
            'file' => UploadedFile::fake()->createWithContent('deed.pdf', 'deed-bytes'),
        ], $overrides));
    };
});

function offerableLawyer(array $attributes = []): array
{
    $lawyer = User::factory()->create();

    $profile = LawyerProfile::factory()->notary()->for($lawyer)->create(array_merge([
        'practice_areas' => ['real_estate'],
        'region' => 'ncr',
    ], $attributes));

    return [$lawyer, $profile];
}

it('creates a paid notarization request and returns the checkout url', function () {
    config(['vetting.default_notarization_fee' => 50000]);

    Http::fake([
        'api.paymongo.com/v1/payment_intents' => Http::response([
            'data' => ['id' => 'pi_test123', 'type' => 'payment_intent', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/customers' => Http::sequence()
            ->push(['data' => []], 200)
            ->push(['data' => ['id' => 'cus_test123', 'type' => 'customer', 'attributes' => []]], 200),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test123',
                'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test123#token'],
            ],
        ]),
    ]);

    $response = ($this->submitRequest)([
        'service_type' => 'notarization',
    ]);

    $response->assertCreated();

    expect($response->json('checkout_url'))->toBe('https://checkout.paymongo.com/cs_test123#token')
        ->and($response->json('data.status'))->toBe('payment_pending')
        ->and($response->json('data.total_fee'))->toBe(53368)
        ->and($response->json('data.vetting_fee'))->toBe(null)
        ->and($response->json('data.notarization_fee'))->toBe(50000)
        ->and($response->json('data.processing_fee'))->toBe(3368)
        ->and($response->json('data.payment_status'))->toBe('none');

    $this->assertDatabaseHas('vetting_requests', [
        'submitter_id' => $this->submitter->id,
        'status' => 'payment_pending',
        'service_type' => 'notarization',
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    $this->assertDatabaseHas('vetting_payments', [
        'submitter_id' => $this->submitter->id,
        'kind' => 'notarization',
        'amount' => 53368,
        'status' => 'pending',
    ]);

    // No lawyer is contacted before the buyer pays.
    $this->assertDatabaseCount('vetting_request_matches', 0);
});

it('charges a flat vetting fee on top of the notarization fee', function () {
    config(['vetting.default_vetting_fee' => 10000, 'vetting.default_notarization_fee' => 0]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'pi_test123']])]);

    $response = ($this->submitRequest)(['service_type' => 'vetting']);

    $response->assertCreated();

    expect($response->json('data.vetting_fee'))->toBe(10000)
        ->and($response->json('data.notarization_fee'))->toBe(null)
        ->and($response->json('data.processing_fee'))->toBe(1918)
        ->and($response->json('data.total_fee'))->toBe(11918);
});

it('charges the flat schedule fee for a simple affidavit', function () {
    config(['vetting.default_vetting_fee' => 10000, 'vetting.default_notarization_fee' => 0]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'pi_test123']])]);

    $response = ($this->submitRequest)([
        'service_type' => 'notarization',
        'document_type' => 'Affidavit of Loss',
    ]);

    $response->assertCreated();

    expect($response->json('data.notarization_fee'))->toBe(32500)
        ->and($response->json('data.total_fee'))->toBe(45596);
});

it('charges one percent of the property value for a deed of absolute sale', function () {
    config(['vetting.default_vetting_fee' => 10000, 'vetting.default_notarization_fee' => 0]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'pi_test123']])]);

    $response = ($this->submitRequest)([
        'service_type' => 'notarization',
        'document_type' => 'Deed of Absolute Sale',
        'property_value' => 3000000,
    ]);

    $response->assertCreated();

    expect($response->json('data.property_value'))->toBe(300000000)
        ->and($response->json('data.notarization_fee'))->toBe(3000000)
        ->and($response->json('data.total_fee'))->toBe(3120726);
});

it('never charges below the schedule minimum for a percentage-based document', function () {
    config(['vetting.default_vetting_fee' => 10000, 'vetting.default_notarization_fee' => 0]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => ['id' => 'pi_test123']])]);

    $response = ($this->submitRequest)([
        'service_type' => 'notarization',
        'document_type' => 'Contract of Lease',
        'property_value' => 50000,
    ]);

    $response->assertCreated();

    // 1% of ₱50,000 is ₱500, below the ₱1,500 minimum.
    expect($response->json('data.notarization_fee'))->toBe(150000)
        ->and($response->json('data.total_fee'))->toBe(167358);
});

it('creates a free vetting request and starts matching immediately', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $response = ($this->submitRequest)()
        ->assertCreated();

    expect($response->json('data.status'))->toBe('matched');

    $request = VettingRequest::firstWhere('submitter_id', $this->submitter->id);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);

    Notification::assertSentTo($lawyer, NewVettingRequest::class);
});

it('waits for a lawyer instead of declining when none is available', function () {
    Notification::fake();

    $response = ($this->submitRequest)()
        ->assertCreated();

    expect($response->json('data.status'))->toBe('waiting');

    $this->assertDatabaseCount('vetting_request_matches', 0);

    Notification::assertSentTo($this->submitter, VettingRequestWaiting::class);
});

it('offers a request only to lawyers whose practice area covers the document type', function () {
    Notification::fake();

    [$realEstateLawyer] = offerableLawyer();
    [$litigationLawyer] = offerableLawyer(['practice_areas' => ['litigation']]);

    ($this->submitRequest)(['document_type' => 'Deed of Absolute Sale'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'matched');

    $request = VettingRequest::firstWhere('submitter_id', $this->submitter->id);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $realEstateLawyer->id,
        'status' => 'notified',
    ]);

    $this->assertDatabaseMissing('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $litigationLawyer->id,
    ]);

    Notification::assertSentTo($realEstateLawyer, NewVettingRequest::class);
    Notification::assertNotSentTo($litigationLawyer, NewVettingRequest::class);
});

it('offers an affidavit to a litigation lawyer, not a real-estate lawyer', function () {
    Notification::fake();

    [$realEstateLawyer] = offerableLawyer();
    [$litigationLawyer] = offerableLawyer(['practice_areas' => ['litigation']]);

    ($this->submitRequest)(['document_type' => 'Affidavit of Loss'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'matched');

    $request = VettingRequest::firstWhere('submitter_id', $this->submitter->id);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $litigationLawyer->id,
        'status' => 'notified',
    ]);

    $this->assertDatabaseMissing('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $realEstateLawyer->id,
    ]);
});

it('lets the submitter retry a waiting request once a lawyer is available', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'status' => VettingRequestStatus::Waiting,
    ]);

    $this->signInAs($this->submitter)
        ->postJson("/api/vetting-requests/{$request->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'matched');

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('matches waiting requests when a lawyer comes online', function () {
    Notification::fake();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'status' => VettingRequestStatus::Waiting,
    ]);

    [$lawyer, $profile] = offerableLawyer();

    (new MatchWaitingRequests($profile))->handle(
        app(VettingRequestService::class),
        app(LawyerMatcher::class),
    );

    expect($request->fresh()->status)->toBe(VettingRequestStatus::Matched);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('matches a nationwide request to a lawyer in a specific region', function () {
    Notification::fake();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'nationwide',
        'status' => VettingRequestStatus::Waiting,
    ]);

    [$lawyer, $profile] = offerableLawyer(['region' => 'region4a']);

    (new MatchWaitingRequests($profile))->handle(
        app(VettingRequestService::class),
        app(LawyerMatcher::class),
    );

    expect($request->fresh()->status)->toBe(VettingRequestStatus::Matched);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('re-offers waiting requests when a lawyer toggles availability on', function () {
    Notification::fake();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'status' => VettingRequestStatus::Waiting,
    ]);

    $lawyer = User::factory()->create();

    LawyerProfile::factory()->verified()->for($lawyer)->create([
        'practice_areas' => ['real_estate'],
        'region' => 'ncr',
        'available' => false,
    ]);

    $this->signInAs($lawyer)
        ->patchJson('/api/lawyer/profile/availability', ['available' => true])
        ->assertOk();

    expect($request->fresh()->status)->toBe(VettingRequestStatus::Matched);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('requires an active subscription to submit a request', function () {
    $planless = User::factory()->create();

    $planless = User::factory()->create();

    $this->signInAs($planless)
        ->postJson('/api/vetting-requests', [
            'document_type' => 'deed',
            'summary' => 'A deed of sale for a Makati condo.',
            'jurisdiction' => 'ncr',
            'service_type' => 'vetting',
            'file' => UploadedFile::fake()->createWithContent('deed.pdf', 'deed-bytes'),
        ])
        ->assertStatus(402)
        ->assertJsonPath('upgrade_required', true);
});

it('starts matching when the payment webhook confirms authorization', function () {
    config(['vetting.default_notarization_fee' => 50000]);

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'service_type' => 'notarization',
        'jurisdiction' => 'ncr',
        'status' => VettingRequestStatus::PaymentPending,
        'vetting_fee' => null,
        'notarization_fee' => 50000,
        'payment_status' => VettingPaymentStatus::Pending,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'attributes' => [
                'type' => 'payment.paid',
                'data' => [
                    'id' => 'pay_test123',
                    'type' => 'payment',
                    'attributes' => ['payment_intent_id' => 'pi_test123'],
                ],
            ],
        ],
    ];

    $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/vetting/webhook', $payload, ['Paymongo-Signature' => $signature])
        ->assertOk();

    $request->refresh();

    expect($request->status)->toBe(VettingRequestStatus::Matched)
        ->and($request->payment_status)->toBe(VettingPaymentStatus::Authorized);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'notified',
    ]);
});

it('rejects webhooks with an invalid signature', function () {
    config(['paymongo.webhook_secret' => 'test-secret']);

    $this->postJson('/api/vetting/webhook', [
        'data' => ['attributes' => ['type' => 'payment.paid']],
    ], ['Paymongo-Signature' => 'not-a-real-signature'])
        ->assertStatus(400);
});

it('lets an offered lawyer accept and locks the request to them', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'summary' => 'A deed of sale for a Makati condo.',
        'jurisdiction' => 'ncr',
        'service_type' => 'notarization',
        'status' => VettingRequestStatus::Matched,
        'notarization_fee' => 50000,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $lawyer->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_NOTARIZATION,
        'amount' => 50000,
        'status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    $this->signInAs($lawyer)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.assigned_lawyer.id', $lawyer->id);

    expect($request->fresh()->locked_at)->not->toBeNull();

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
        'status' => 'accepted',
    ]);

    // The payment now accrues to the lawyer who took the work.
    $this->assertDatabaseHas('vetting_payments', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $lawyer->id,
    ]);

    Notification::assertSentTo($this->submitter, VettingRequestAccepted::class);
});

it('escalates other offers when one lawyer accepts', function () {
    Notification::fake();

    [$winner, $winnerProfile] = offerableLawyer();
    [$loser] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'service_type' => 'notarization',
        'status' => VettingRequestStatus::Matched,
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $winner->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $loser->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    $this->signInAs($winner)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/accept")
        ->assertOk();

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $loser->id,
        'status' => 'escalated',
    ]);
});

it('refuses an accept from a lawyer who was never offered', function () {
    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
    ]);

    $stranger = User::factory()->create();

    $this->signInAs($stranger)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/accept")
        ->assertStatus(403);
});

it('declines a request while other lawyers are still offered', function () {
    [$first] = offerableLawyer();
    [$second] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $first->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $second->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    $this->signInAs($first)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/decline")
        ->assertOk()
        ->assertJsonPath('data.status', 'matched');

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $first->id,
        'status' => 'declined',
    ]);
});

it('keeps the request waiting when every offered lawyer declines', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
        'notarization_fee' => 50000,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $lawyer->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    $this->signInAs($lawyer)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/decline")
        ->assertOk()
        ->assertJsonPath('data.status', 'waiting');

    expect($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Authorized);

    Http::assertNothingSent();

    Notification::assertSentTo($this->submitter, VettingRequestWaiting::class);
});

it('cancels an unassigned request and releases the held payment', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
        'notarization_fee' => 50000,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $lawyer->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    Http::fake([
        'api.paymongo.com/v1/payment_intents/pi_test123/cancel' => Http::response([
            'data' => ['id' => 'pi_test123', 'type' => 'payment_intent', 'attributes' => []],
        ]),
    ]);

    $this->signInAs($this->submitter)
        ->postJson("/api/vetting-requests/{$request->id}/cancel", [
            'reason' => 'Changed my mind.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'Changed my mind.');

    expect($request->fresh()->cancelled_at)->not->toBeNull()
        ->and($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Void);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/payment_intents/pi_test123/cancel'));

    Notification::assertSentTo($lawyer, VettingRequestCancelled::class);
});

it('refuses to cancel a request that has already been accepted', function () {
    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Accepted,
        'assigned_lawyer_id' => $lawyer->id,
    ]);

    $this->signInAs($this->submitter)
        ->postJson("/api/vetting-requests/{$request->id}/cancel")
        ->assertStatus(422);
});

it('escalates expired offers to the next pool', function () {
    [$first] = offerableLawyer();
    [$second] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'jurisdiction' => 'ncr',
        'status' => VettingRequestStatus::Matched,
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $first->id,
        'status' => VettingMatchStatus::Notified,
        'expires_at' => now()->subHour(),
    ]);

    $this->travel(25)->hours();

    (new EscalateVettingRequest($request))->handle(app(VettingRequestService::class));

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $first->id,
        'status' => 'expired',
    ]);

    $this->assertDatabaseHas('vetting_request_matches', [
        'vetting_request_id' => $request->id,
        'lawyer_id' => $second->id,
        'status' => 'notified',
    ]);
});

it('keeps the request waiting when no eligible lawyer is left', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
        'notarization_fee' => 50000,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $lawyer->id,
        'status' => VettingMatchStatus::Notified,
        'expires_at' => now()->subHour(),
    ]);

    $this->travel(25)->hours();

    (new EscalateVettingRequest($request))->handle(app(VettingRequestService::class));

    expect($request->fresh()->status)->toBe(VettingRequestStatus::Waiting)
        ->and($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Authorized);

    Http::assertNothingSent();

    Notification::assertSentTo($this->submitter, VettingRequestWaiting::class);
});

it('completes a vet-only request when marked vetted and captures the fee', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::UnderReview,
        'assigned_lawyer_id' => $lawyer->id,
        'vetting_fee' => 25000,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'kind' => VettingPayment::KIND_VETTING,
        'amount' => 25000,
        'status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    Http::fake([
        'api.paymongo.com/v1/payment_intents/pi_test123/capture' => Http::response([
            'data' => ['id' => 'pi_test123', 'type' => 'payment_intent', 'attributes' => []],
        ]),
    ]);

    $this->signInAs($lawyer)
        ->patchJson("/api/lawyer/vetting-requests/{$request->id}/status", ['status' => 'vetted'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($request->fresh()->completed_at)->not->toBeNull()
        ->and($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Captured);

    $this->assertDatabaseHas('vetting_payments', [
        'vetting_request_id' => $request->id,
        'status' => 'captured',
    ]);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/payment_intents/pi_test123/capture'));

    Notification::assertSentTo($this->submitter, VettingRequestStatusChanged::class);
});

it('requires review before vetting', function () {
    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Accepted,
        'assigned_lawyer_id' => $lawyer->id,
    ]);

    $this->signInAs($lawyer)
        ->patchJson("/api/lawyer/vetting-requests/{$request->id}/status", ['status' => 'vetted'])
        ->assertStatus(422);
});

it('notarizes an accepted document end to end', function () {
    Notification::fake();

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'document_type' => 'deed',
        'service_type' => 'notarization',
        'status' => VettingRequestStatus::UnderReview,
        'assigned_lawyer_id' => $lawyer->id,
        'notarization_fee' => 50000,
        'payment_status' => VettingPaymentStatus::Authorized,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    Http::fake([
        'api.paymongo.com/v1/payment_intents/pi_test123/capture' => Http::response([
            'data' => ['id' => 'pi_test123', 'type' => 'payment_intent', 'attributes' => []],
        ]),
    ]);

    $this->signInAs($lawyer)
        ->patchJson("/api/lawyer/vetting-requests/{$request->id}/status", ['status' => 'vetted'])
        ->assertOk()
        ->assertJsonPath('data.status', 'vetted');

    $this->signInAs($lawyer)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/schedule", [
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'vetted')
        ->assertJsonPath('data.session_provider', 'whereby');

    expect($request->fresh()->session_scheduled_at)->not->toBeNull()
        ->and($request->fresh()->session_link)->not->toBeNull();

    Notification::assertSentTo($this->submitter, NotarizationScheduled::class);

    $this->signInAs($lawyer)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/notarize", [
            'signer_name' => 'Juan Dela Cruz',
            'id_type' => 'Government Issued ID',
            'id_number' => '1234-5678-9012',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.certificate_number', $request->fresh()->certificate_number);

    $journal = NotarialJournalEntry::firstWhere('vetting_request_id', $request->id);

    expect($journal)->not->toBeNull()
        ->and($journal->lawyer_id)->toBe($lawyer->id)
        ->and($journal->signer_name)->toBe('Juan Dela Cruz')
        ->and($journal->verification_method)->toBe('remote_online_video')
        ->and($request->fresh()->payment_status)->toBe(VettingPaymentStatus::Captured);
});

it('refuses to notarize before the session is scheduled', function () {
    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'service_type' => 'notarization',
        'status' => VettingRequestStatus::Vetted,
        'assigned_lawyer_id' => $lawyer->id,
    ]);

    $this->signInAs($lawyer)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/notarize", [
            'signer_name' => 'Juan Dela Cruz',
            'id_type' => 'Government Issued ID',
            'id_number' => '1234-5678-9012',
        ])
        ->assertStatus(422);
});

it('only notaries with an active commission can accept notarizations', function () {
    $plain = User::factory()->create();
    LawyerProfile::factory()->verified()->for($plain)->create([
        'practice_areas' => ['real_estate'],
        'region' => 'ncr',
    ]);

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'service_type' => 'notarization',
        'status' => VettingRequestStatus::Matched,
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $plain->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    $this->signInAs($plain)
        ->postJson("/api/lawyer/vetting-requests/{$request->id}/accept")
        ->assertStatus(422);
});

it('opens the clarification thread to the submitter and the assigned lawyer only', function () {
    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Accepted,
        'assigned_lawyer_id' => $lawyer->id,
    ]);

    $this->signInAs($this->submitter)
        ->postJson("/api/vetting-requests/{$request->id}/messages", ['body' => 'Please check page 3.'])
        ->assertCreated();

    $this->signInAs($lawyer)
        ->postJson("/api/vetting-requests/{$request->id}/messages", ['body' => 'Noted, will do.'])
        ->assertCreated();

    $this->signInAs($lawyer)
        ->getJson("/api/vetting-requests/{$request->id}/messages")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $stranger = User::factory()->create();

    $this->signInAs($stranger)
        ->postJson("/api/vetting-requests/{$request->id}/messages", ['body' => 'snooping'])
        ->assertForbidden();
});

it('lets the submitter view their document but keeps it from unaccepted lawyers', function () {
    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
        'document_id' => null,
    ]);

    VettingRequestMatch::factory()->for($request)->create([
        'lawyer_id' => $lawyer->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    $this->signInAs($this->submitter)
        ->getJson("/api/vetting-requests/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'matched');

    // Offered but not assigned: sees the summary, not the full document.
    $this->signInAs($lawyer)
        ->getJson("/api/lawyer/vetting-requests/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.my_match', 'notified');

    $stranger = User::factory()->create();

    $this->signInAs($stranger)
        ->getJson("/api/lawyer/vetting-requests/{$request->id}")
        ->assertForbidden();
});

it('lists the requests a lawyer has been offered or holds', function () {
    [$lawyer] = offerableLawyer();

    $assigned = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Accepted,
        'assigned_lawyer_id' => $lawyer->id,
    ]);

    $offered = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
    ]);

    VettingRequestMatch::factory()->for($offered)->create([
        'lawyer_id' => $lawyer->id,
        'status' => VettingMatchStatus::Notified,
    ]);

    $response = $this->signInAs($lawyer)
        ->getJson('/api/lawyer/vetting-requests')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->pluck('id'))
        ->toContain($assigned->id, $offered->id);
});

it('aggregates captured notarizations into a weekly payout and notifies the lawyer', function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-08-19 12:00:00'));

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Completed,
        'assigned_lawyer_id' => $lawyer->id,
        'notarization_fee' => 50000,
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'lawyer_id' => $lawyer->id,
        'kind' => VettingPayment::KIND_NOTARIZATION,
        'amount' => 50000,
        'status' => VettingPaymentStatus::Captured,
        'gateway_payment_intent_id' => 'pi_test123',
        'captured_at' => Carbon::parse('2026-08-12 10:00:00'),
    ]);

    $this->artisan('vetting:payouts-generate')->assertSuccessful();

    $payout = LawyerPayout::firstWhere('lawyer_id', $lawyer->id);

    expect($payout)->not->toBeNull()
        ->and($payout->gross_amount)->toBe(50000)
        ->and($payout->platform_fee)->toBe(5000)
        ->and($payout->lawyer_share)->toBe(45000)
        ->and($payout->notarization_count)->toBe(1);

    Notification::assertSentTo($lawyer, LawyerPayoutAvailable::class);
});

it('never double-pays a lawyer for the same period', function () {
    $this->travelTo(Carbon::parse('2026-08-19 12:00:00'));

    [$lawyer] = offerableLawyer();

    $request = VettingRequest::factory()->for($this->submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Completed,
        'assigned_lawyer_id' => $lawyer->id,
        'notarization_fee' => 50000,
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $this->submitter->id,
        'lawyer_id' => $lawyer->id,
        'kind' => VettingPayment::KIND_NOTARIZATION,
        'amount' => 50000,
        'status' => VettingPaymentStatus::Captured,
        'gateway_payment_intent_id' => 'pi_test123',
        'captured_at' => Carbon::parse('2026-08-12 10:00:00'),
    ]);

    $this->artisan('vetting:payouts-generate')->assertSuccessful();
    $this->artisan('vetting:payouts-generate')->assertSuccessful();

    expect(LawyerPayout::where('lawyer_id', $lawyer->id)->count())->toBe(1);
});
