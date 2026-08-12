<?php

use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Auth\SupabaseJwtService;
use App\Support\PlanLimits;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->starter = Plan::factory()->starter()->create();
    $this->pro = Plan::factory()->pro()->create();
});

it('lists the active plans for a guest', function () {
    $response = $this->getJson('/api/plans')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.slug'))->toBe('starter')
        ->and($response->json('data.0.price_label'))->toBe('₱1,500')
        ->and($response->json('data.1.slug'))->toBe('pro');
});

it('provisions a first-time Supabase user without any subscription', function () {
    // There is no registration endpoint any more: the account is created in
    // Supabase and imported here the first time its token reaches the API.
    $token = app(SupabaseJwtService::class)->mintToken(
        (string) Str::uuid(),
        'new@example.com',
        ['full_name' => 'New User'],
    );

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'new@example.com');

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect($user->name)->toBe('New User')
        ->and($user->supabase_uid)->not->toBeNull()
        ->and($user->subscription)->toBeNull();
});

it('starts a subscription and returns the hosted checkout url', function () {
    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::sequence()
            ->push(['data' => []], 200)
            ->push([
                'data' => ['id' => 'cus_test123', 'type' => 'customer', 'attributes' => []],
            ], 200),
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
                            'attributes' => [
                                'client_key' => 'pi_test123_ck_test',
                            ],
                        ],
                    ],
                ],
            ],
        ]),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_test123',
                'type' => 'checkout_session',
                'attributes' => [
                    'checkout_url' => 'https://checkout.paymongo.com/cs_test123#token',
                ],
            ],
        ]),
    ]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertCreated();

    expect($response->json('checkout.checkout_url'))->toBe('https://checkout.paymongo.com/cs_test123#token')
        ->and($response->json('checkout.payment_intent_id'))->toBe('pi_test123')
        ->and($response->json('data.status'))->toBe(Subscription::STATUS_INCOMPLETE);

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->pro->id,
        'paymongo_subscription_id' => 'subs_test123',
        'paymongo_customer_id' => 'cus_test123',
    ]);

    Http::assertSentCount(5);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/checkout_sessions')
            && data_get($request->data(), 'data.attributes.payment_intent_id') === 'pi_test123'
            && data_get($request->data(), 'data.attributes.customer_id') === 'cus_test123'
            && data_get($request->data(), 'data.attributes.line_items.0.amount') === $this->pro->price;
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/customers')
            && data_get($request->data(), 'data.attributes.email') === $this->user->email
            && data_get($request->data(), 'data.attributes.default_device') === 'email'
            && data_get($request->data(), 'data.attributes.first_name') !== null
            && data_get($request->data(), 'data.attributes.last_name') !== null;
    });
});

it('reuses an existing PayMongo customer by email instead of creating one', function () {
    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::response([
            'data' => [
                ['id' => 'cus_existing', 'type' => 'customer', 'attributes' => ['email' => $this->user->email]],
            ],
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
                            'attributes' => ['client_key' => 'pi_test123_ck_test'],
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

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertCreated();

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'paymongo_customer_id' => 'cus_existing',
    ]);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/v1/customers')
            && $request->method() === 'GET'
            && ($query['email'] ?? null) === $this->user->email;
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/v1/customers')
            && $request->method() === 'POST';
    });
});

it('rejects subscribing while an active subscription exists', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertStatus(422);
});

it('rejects starting a checkout while a payment is already pending', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->pro->id,
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A subscription is already pending. Cancel it before starting a new checkout.');
});

it('rejects a checkout while another checkout is being created', function () {
    Cache::lock("subscription.checkout.{$this->user->id}", 30)->get();

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id])
        ->assertStatus(409);

    Cache::lock("subscription.checkout.{$this->user->id}", 30)->forceRelease();
});

it('shows the current subscription with usage', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => 42,
        'documents_uploaded' => 3,
    ]);

    $response = $this->signInAs($this->user)->getJson('/api/subscription')->assertOk();

    expect($response->json('data.plan.slug'))->toBe('pro')
        ->and($response->json('data.usage.messages.used'))->toBe(42)
        ->and($response->json('data.usage.messages.limit'))->toBe(500)
        ->and($response->json('data.usage.messages.overage'))->toBe(0)
        ->and($response->json('data.usage.documents.limit'))->toBe(100);
});

it('returns null subscription for users without one', function () {
    $this->signInAs($this->user)->getJson('/api/subscription')
        ->assertOk()
        ->assertJson(['data' => null]);
});

it('changes the subscription plan', function () {
    $this->starter->update(['paymongo_plan_id' => 'plan_starter']);
    $this->pro->update(['paymongo_plan_id' => 'plan_pro']);

    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->starter->id,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    $response = $this->signInAs($this->user)
        ->postJson('/api/subscription/change-plan', ['plan_id' => $this->pro->id])
        ->assertOk();

    expect($response->json('data.plan.slug'))->toBe('pro')
        ->and(Subscription::firstWhere('user_id', $this->user->id)->plan_id)->toBe($this->pro->id);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/subscriptions/subs_test123/plan'));
});

it('cancels the subscription', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->pro->id,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    Http::fake(['api.paymongo.com/*' => Http::response(['data' => []])]);

    $this->signInAs($this->user)->postJson('/api/subscription/cancel')->assertOk();

    $subscription = Subscription::firstWhere('user_id', $this->user->id);

    expect($subscription->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($subscription->cancelled_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/subscriptions/subs_test123/cancel'));
});

it('activates a subscription on invoice paid webhook', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->starter->id,
        'paymongo_subscription_id' => 'subs_test123',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'type' => 'subscription.invoice.paid',
                'data' => [
                    'id' => 'inv_test123',
                    'type' => 'invoice',
                    'attributes' => ['resource_id' => 'subs_test123'],
                ],
            ],
        ],
    ];

    $signature = 'paymongo_'.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook', $payload, [
        'Paymongo-Signature' => $signature,
    ])->assertOk();

    $subscription = Subscription::firstWhere('paymongo_subscription_id', 'subs_test123');

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->current_period_end)->not->toBeNull();
});

it('mirrors a subscription status update webhook', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->starter->id,
        'paymongo_subscription_id' => 'subs_test123',
    ]);

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'type' => 'subscription.updated',
                'data' => [
                    'id' => 'subs_test123',
                    'type' => 'subscription',
                    'attributes' => ['status' => 'past_due'],
                ],
            ],
        ],
    ];

    $signature = 'paymongo_'.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook', $payload, [
        'Paymongo-Signature' => $signature,
    ])->assertOk();

    expect(Subscription::firstWhere('paymongo_subscription_id', 'subs_test123')->status)
        ->toBe(Subscription::STATUS_PAST_DUE);
});

it('rejects webhooks with an invalid signature', function () {
    config(['paymongo.webhook_secret' => 'test-secret']);

    $this->postJson('/api/subscriptions/webhook', [
        'data' => ['attributes' => ['type' => 'subscription.updated']],
    ], [
        'Paymongo-Signature' => 'not-a-real-signature',
    ])->assertStatus(400);
});

it('blocks a message when the monthly message limit is reached', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->starter->id]);
    UsageCounter::factory()->for($this->user)->create(['messages_used' => 200, 'documents_uploaded' => 0]);

    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Hello'])
        ->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('blocks messages for users without a subscription', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Hello'])
        ->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('blocks all product endpoints for users without a subscription', function () {
    $response = $this->signInAs($this->user)->getJson('/api/documents')->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('blocks document upload when the monthly upload limit is reached', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->starter->id]);
    UsageCounter::factory()->for($this->user)->create(['messages_used' => 0, 'documents_uploaded' => 10]);
    Queue::fake();
    Storage::fake('local');

    $response = $this->signInAs($this->user)->postJson('/api/documents', [
        'file' => UploadedFile::fake()->createWithContent('memo.txt', 'x'),
    ])->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('increments document upload usage', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    Queue::fake();
    Storage::fake('local');

    $this->signInAs($this->user)->postJson('/api/documents', [
        'file' => UploadedFile::fake()->createWithContent('memo.txt', 'x'),
    ])->assertCreated();

    expect(UsageCounter::firstWhere('user_id', $this->user->id)->documents_uploaded)->toBe(1);
});

it('blocks creating a case over the active case limit', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->starter->id]);
    LegalCase::factory()->for($this->user)->state(['status' => 'open'])->count(10)->create();

    $response = $this->signInAs($this->user)->postJson('/api/cases', [
        'title' => 'Another case',
        'case_type' => 'legal',
        'status' => 'open',
        'priority' => 'medium',
    ])->assertStatus(402);

    expect($response->json('upgrade_required'))->toBeTrue();
});

it('counts messages beyond the cap as overage when the plan has an overage price', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => 500,
        'messages_overage' => 2,
        'documents_uploaded' => 0,
    ]);

    PlanLimits::consumeMessage($this->user);

    $counter = UsageCounter::firstWhere('user_id', $this->user->id);

    expect($counter->messages_used)->toBe(500)
        ->and($counter->messages_overage)->toBe(3);
});

it('blocks over-cap messages when the plan has no overage price', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->starter->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => 200,
        'documents_uploaded' => 0,
    ]);

    expect(fn () => PlanLimits::consumeMessage($this->user))
        ->toThrow(HttpResponseException::class)
        ->and(UsageCounter::firstWhere('user_id', $this->user->id)->messages_overage)->toBe(0);
});

it('exposes the overage balance in the subscription payload', function () {
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
    UsageCounter::factory()->for($this->user)->create([
        'messages_used' => 510,
        'messages_overage' => 10,
        'documents_uploaded' => 0,
    ]);

    $response = $this->signInAs($this->user)->getJson('/api/subscription')->assertOk();

    expect($response->json('data.usage.messages.overage'))->toBe(10)
        ->and($response->json('data.usage.messages.overage_rate'))->toBe(350)
        ->and($response->json('data.usage.messages.overage_due_cents'))->toBe(3500)
        ->and($response->json('data.usage.messages.overage_due_pesos'))->toBe(35);
});

it('starts an annual subscription with a yearly PayMongo plan', function () {
    $this->pro->update(['paymongo_plan_id' => 'plan_pro_monthly']);

    Http::fake([
        'api.paymongo.com/v1/customers*' => Http::response([
            'data' => ['id' => 'cus_annual', 'type' => 'customer', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions/plans' => Http::response([
            'data' => ['id' => 'plan_pro_annual', 'type' => 'plan', 'attributes' => []],
        ]),
        'api.paymongo.com/v1/subscriptions' => Http::response([
            'data' => [
                'id' => 'subs_annual',
                'type' => 'subscription',
                'attributes' => [
                    'latest_invoice' => [
                        'payment_intent' => [
                            'id' => 'pi_annual',
                            'attributes' => ['client_key' => 'pi_annual_ck'],
                        ],
                    ],
                ],
            ],
        ]),
        'api.paymongo.com/v1/checkout_sessions' => Http::response([
            'data' => [
                'id' => 'cs_annual',
                'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_annual#token'],
            ],
        ]),
    ]);

    $this->signInAs($this->user)
        ->postJson('/api/subscription', ['plan_id' => $this->pro->id, 'billing_interval' => 'annual'])
        ->assertCreated();

    $this->assertDatabaseHas('subscriptions', [
        'user_id' => $this->user->id,
        'plan_id' => $this->pro->id,
        'interval' => 'annual',
        'paymongo_customer_id' => 'cus_annual',
    ]);

    $this->assertDatabaseHas('plans', [
        'id' => $this->pro->id,
        'paymongo_plan_id_annual' => 'plan_pro_annual',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/subscriptions/plans')
            && data_get($request->data(), 'data.attributes.interval') === 'yearly'
            && data_get($request->data(), 'data.attributes.amount') === 1990000;
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/checkout_sessions')
            && data_get($request->data(), 'data.attributes.line_items.0.amount') === 1990000;
    });
});

it('extends the current period to a year for annual invoice payments', function () {
    Subscription::factory()->for($this->user)->create([
        'plan_id' => $this->starter->id,
        'interval' => 'annual',
        'paymongo_subscription_id' => 'subs_annual',
        'status' => Subscription::STATUS_INCOMPLETE,
    ]);

    config(['paymongo.webhook_secret' => 'test-secret']);

    $payload = [
        'data' => [
            'type' => 'event',
            'attributes' => [
                'type' => 'subscription.invoice.paid',
                'data' => [
                    'id' => 'inv_annual',
                    'type' => 'invoice',
                    'attributes' => ['resource_id' => 'subs_annual'],
                ],
            ],
        ],
    ];

    $signature = 'paymongo_'.hash_hmac('sha256', json_encode($payload), 'test-secret');

    $this->postJson('/api/subscriptions/webhook', $payload, [
        'Paymongo-Signature' => $signature,
    ])->assertOk();

    $subscription = Subscription::firstWhere('paymongo_subscription_id', 'subs_annual');

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->current_period_end->year)->toBe(now()->addYear()->year);
});
