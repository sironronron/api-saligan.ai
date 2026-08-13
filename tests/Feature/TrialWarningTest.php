<?php

use App\Mail\TrialEndingMail;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialCode;
use App\Models\User;
use App\Notifications\TrialEnding;
use App\Services\Billing\TrialRedeemer;
use App\Services\Billing\TrialWarner;
use App\Support\PlanLimits;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    config([
        'saligan.trials.warn_days_remaining' => 3,
        'saligan.trials.warn_messages_remaining' => 10,
    ]);

    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->memberOf($this->organization)->create();
});

/**
 * A live trial on a plan with the given message cap.
 */
function trialWith(User $user, int $messageCap, int $days = 14): Subscription
{
    $plan = Plan::factory()->create([
        'sort_order' => 0,
        'overage_price' => null,
        'limits' => ['active_cases' => null, 'documents_uploaded' => null, 'messages_used' => $messageCap],
    ]);

    $code = TrialCode::factory()->create(['plan_id' => $plan->id, 'trial_days' => $days]);

    return app(TrialRedeemer::class)->redeem($user, $code->code);
}

it('warns when the trial drops inside the day threshold', function () {
    $subscription = trialWith($this->user, messageCap: 1000, days: 14);
    $subscription->forceFill(['trial_ends_at' => now()->addDays(2)])->save();

    expect(app(TrialWarner::class)->sweepExpiringTrials())->toBe(1);

    Notification::assertSentTo($this->user, TrialEnding::class, function (TrialEnding $n): bool {
        return $n->reason === TrialEndingMail::REASON_DAYS;
    });
});

it('leaves trials outside the day threshold alone', function () {
    $subscription = trialWith($this->user, messageCap: 1000, days: 14);
    $subscription->forceFill(['trial_ends_at' => now()->addDays(9)])->save();

    expect(app(TrialWarner::class)->sweepExpiringTrials())->toBe(0);

    Notification::assertNothingSent();
});

it('warns once and never again', function () {
    // The sweep runs daily and the trial stays inside the window the whole
    // time, so without the stamp this would email every morning.
    $subscription = trialWith($this->user, messageCap: 1000, days: 14);
    $subscription->forceFill(['trial_ends_at' => now()->addDays(2)])->save();

    $warner = app(TrialWarner::class);

    expect($warner->sweepExpiringTrials())->toBe(1)
        ->and($warner->sweepExpiringTrials())->toBe(0)
        ->and($warner->sweepExpiringTrials())->toBe(0);

    Notification::assertSentToTimes($this->user, TrialEnding::class, 1);
});

it('warns when the message allowance runs low', function () {
    trialWith($this->user, messageCap: 12);

    // Threshold is 10 remaining, so the second message crosses it.
    PlanLimits::consumeMessage($this->user->fresh());
    Notification::assertNothingSent();

    PlanLimits::consumeMessage($this->user->fresh());

    Notification::assertSentTo($this->user, TrialEnding::class, function (TrialEnding $n): bool {
        return $n->reason === TrialEndingMail::REASON_MESSAGES && $n->remaining === 10;
    });
});

it('does not warn again as further messages are spent', function () {
    trialWith($this->user, messageCap: 12);

    foreach (range(1, 5) as $ignored) {
        PlanLimits::consumeMessage($this->user->fresh());
    }

    Notification::assertSentToTimes($this->user, TrialEnding::class, 1);
});

it('does not warn on the message that exhausts the allowance', function () {
    // Exhaustion is surfaced on the request itself; a "running low" email fired
    // by the very message that ended the trial would arrive already stale.
    trialWith($this->user, messageCap: 1);

    PlanLimits::consumeMessage($this->user->fresh());

    expect($this->user->fresh()->subscription->onTrial())->toBeFalse();

    Notification::assertNothingSent();
});

it('counts the allowance across the organization when warning', function () {
    trialWith($this->user, messageCap: 12);

    $colleague = User::factory()->memberOf($this->organization)->create();

    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($colleague->fresh());

    Notification::assertSentToTimes($this->user, TrialEnding::class, 1);
});

it('leads with messages when they will run out before the days do', function () {
    $subscription = trialWith($this->user, messageCap: 100);
    $subscription->forceFill(['trial_ends_at' => now()->addDays(3)])->save();

    // 2 messages left against 3 days: the messages are the real deadline.
    for ($i = 0; $i < 98; $i++) {
        $this->user->usageCounterForCurrentPeriod()->increment(PlanLimits::MESSAGE_KEY);
    }

    app(TrialWarner::class)->sweepExpiringTrials();

    Notification::assertSentTo($this->user, TrialEnding::class, function (TrialEnding $n): bool {
        return $n->reason === TrialEndingMail::REASON_MESSAGES && $n->remaining === 2;
    });
});

it('never warns a paid subscription', function () {
    $plan = Plan::factory()->create([
        'limits' => ['active_cases' => null, 'documents_uploaded' => null, 'messages_used' => 12],
    ]);

    Subscription::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->user->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($this->user->fresh());

    expect(app(TrialWarner::class)->sweepExpiringTrials())->toBe(0);

    Notification::assertNothingSent();
});

it('stops warning entirely when the thresholds are turned off', function () {
    config([
        'saligan.trials.warn_days_remaining' => 0,
        'saligan.trials.warn_messages_remaining' => 0,
    ]);

    $subscription = trialWith($this->user, messageCap: 12);
    $subscription->forceFill(['trial_ends_at' => now()->addDay()])->save();

    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($this->user->fresh());

    expect(app(TrialWarner::class)->sweepExpiringTrials())->toBe(0);

    Notification::assertNothingSent();
});

it('reports the number of warnings sent from the command', function () {
    $subscription = trialWith($this->user, messageCap: 1000, days: 14);
    $subscription->forceFill(['trial_ends_at' => now()->addDay()])->save();

    test()->artisan('trials:warn')
        ->expectsOutputToContain('Trial warnings sent: 1')
        ->assertExitCode(0);
});

it('renders both variants of the warning email', function () {
    // A broken blade would otherwise only surface when a real trial hit the
    // threshold in production.
    $subscription = trialWith($this->user, messageCap: 100);

    foreach ([TrialEndingMail::REASON_DAYS, TrialEndingMail::REASON_MESSAGES] as $reason) {
        $rendered = (new TrialEndingMail($subscription, $reason, 3))->render();

        expect($rendered)->toContain('Saligan')->toContain('/pricing');
    }
});

it('names the actual constraint in the subject line', function () {
    $subscription = trialWith($this->user, messageCap: 100);

    expect((new TrialEndingMail($subscription, TrialEndingMail::REASON_MESSAGES, 1))->envelope()->subject)
        ->toBe('1 message left on your Saligan.AI trial')
        ->and((new TrialEndingMail($subscription, TrialEndingMail::REASON_DAYS, 3))->envelope()->subject)
        ->toBe('3 days left on your Saligan.AI trial');
});
