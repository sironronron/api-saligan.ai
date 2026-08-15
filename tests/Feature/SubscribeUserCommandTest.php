<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

it('grants a user an active subscription on a specific plan', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()->pro()->create();

    $this->artisan('subscribe:user', [
        'user' => $user->email,
        '--plan' => Plan::SLUG_PRO,
    ])->assertExitCode(0);

    $subscription = $user->subscription;

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->interval)->toBe(Plan::INTERVAL_MONTHLY)
        ->and($subscription->current_period_start->toDateString())->toBe(now()->toDateString())
        ->and($subscription->current_period_end->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($subscription->paymongo_subscription_id)->toBeNull();
});

it('accepts the user id instead of the email', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()->standard()->create();

    $this->artisan('subscribe:user', [
        'user' => $user->id,
        '--plan' => Plan::SLUG_STANDARD,
    ])->assertExitCode(0);

    expect($user->subscription->plan_id)->toBe($plan->id);
});

it('supports an annual interval', function () {
    $user = User::factory()->create();
    Plan::factory()->firm()->create();

    $this->artisan('subscribe:user', [
        'user' => $user->email,
        '--plan' => Plan::SLUG_FIRM,
        '--interval' => Plan::INTERVAL_ANNUAL,
    ])->assertExitCode(0);

    $subscription = $user->subscription;

    expect($subscription->interval)->toBe(Plan::INTERVAL_ANNUAL)
        ->and($subscription->current_period_end->toDateString())->toBe(now()->addYear()->toDateString());
});

it('moves an existing subscription to the new plan', function () {
    $user = User::factory()->create();
    Plan::factory()->standard()->create();
    $pro = Plan::factory()->pro()->create();

    $user->subscriptions()->create([
        'plan_id' => $pro->id,
        'interval' => Plan::INTERVAL_MONTHLY,
        'status' => Subscription::STATUS_CANCELLED,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
        'cancelled_at' => now()->subDay(),
    ]);

    $this->artisan('subscribe:user', [
        'user' => $user->email,
        '--plan' => Plan::SLUG_STANDARD,
    ])->assertExitCode(0);

    expect($user->subscriptions()->count())->toBe(1)
        ->and($user->subscription->plan->slug)->toBe(Plan::SLUG_STANDARD)
        ->and($user->subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($user->subscription->cancelled_at)->toBeNull();
});

it('fails with a non-zero exit code when the user is not found', function () {
    Plan::factory()->standard()->create();

    $this->artisan('subscribe:user', [
        'user' => 'missing@example.com',
        '--plan' => Plan::SLUG_STANDARD,
    ])->assertExitCode(1);
});

it('fails with a non-zero exit code when the plan is not found', function () {
    $user = User::factory()->create();

    $this->artisan('subscribe:user', [
        'user' => $user->email,
        '--plan' => 'nonexistent',
    ])->assertExitCode(1);
});
