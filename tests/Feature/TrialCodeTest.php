<?php

use App\Exceptions\TrialRedemptionException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialCode;
use App\Models\User;
use App\Services\Billing\TrialRedeemer;
use App\Support\PlanLimits;
use Illuminate\Http\Exceptions\HttpResponseException;

beforeEach(function () {
    // The trial plan is what a code without a plan of its own falls to; the
    // paid tiers are here for codes that name one.
    $this->trial = Plan::factory()->trial()->create();
    $this->standard = Plan::factory()->create(['slug' => 'standard', 'name' => 'Standard', 'sort_order' => 1]);
    $this->firm = Plan::factory()->create(['slug' => 'firm', 'name' => 'Firm', 'sort_order' => 3]);

    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->memberOf($this->organization)->create();
});

function redeemAs(User $user, string $code)
{
    return test()->signInAs($user)->postJson('/api/trial/redeem', ['code' => $code]);
}

it('starts a trial when a valid code is redeemed', function () {
    $code = TrialCode::factory()->create(['trial_days' => 14]);

    redeemAs($this->user, $code->code)
        ->assertCreated()
        ->assertJsonPath('data.status', Subscription::STATUS_TRIALING)
        ->assertJsonPath('data.trial.on_trial', true);

    $subscription = Subscription::where('organization_id', $this->organization->id)->firstOrFail();

    expect($subscription->onTrial())->toBeTrue()
        ->and($subscription->trial_code_id)->toBe($code->id)
        ->and($subscription->trial_ends_at->isAfter(now()->addDays(13)))->toBeTrue();

    expect($code->fresh()->redeemed_count)->toBe(1);
});

it('grants product access for the length of the trial', function () {
    // The whole point of the feature: a trialing subscription has to satisfy
    // the same gate a paid one does.
    $code = TrialCode::factory()->create();

    redeemAs($this->user, $code->code)->assertCreated();

    expect($this->user->fresh()->subscription->isActive())->toBeTrue();
});

it('stops granting access once the trial lapses', function () {
    $code = TrialCode::factory()->create(['trial_days' => 7]);

    redeemAs($this->user, $code->code)->assertCreated();

    $subscription = Subscription::where('organization_id', $this->organization->id)->firstOrFail();
    $subscription->update(['trial_ends_at' => now()->subMinute()]);

    expect($subscription->fresh()->isActive())->toBeFalse();
});

it('matches the code regardless of casing or surrounding space', function () {
    $code = TrialCode::factory()->create(['code' => 'BETA-2026']);

    redeemAs($this->user, '  beta-2026 ')->assertCreated();
});

it('trials on the code plan when it names one', function () {
    $code = TrialCode::factory()->create(['plan_id' => $this->firm->id]);

    redeemAs($this->user, $code->code)
        ->assertCreated()
        ->assertJsonPath('data.plan.name', 'Firm');
});

it('falls back to the free trial plan when the code names none', function () {
    $code = TrialCode::factory()->create(['plan_id' => null]);

    redeemAs($this->user, $code->code)
        ->assertCreated()
        ->assertJsonPath('data.plan.name', 'Free trial')
        // A quarter of Standard's allowance, which is the whole point of the
        // trial plan existing rather than trialling on Standard itself.
        ->assertJsonPath('data.usage.messages.limit', $this->trial->limits['messages_used']);
});

it('falls back to the cheapest active plan when no trial plan is seeded', function () {
    $this->trial->delete();

    $code = TrialCode::factory()->create(['plan_id' => null]);

    redeemAs($this->user, $code->code)
        ->assertCreated()
        ->assertJsonPath('data.plan.name', 'Standard');
});

it('rejects an unknown code', function () {
    redeemAs($this->user, 'NOPE-1234')->assertStatus(422);

    expect(Subscription::count())->toBe(0);
});

it('rejects an expired code', function () {
    $code = TrialCode::factory()->expired()->create();

    redeemAs($this->user, $code->code)->assertStatus(422);
});

it('rejects a deactivated code', function () {
    $code = TrialCode::factory()->inactive()->create();

    redeemAs($this->user, $code->code)->assertStatus(422);
});

it('rejects a code that has been fully claimed', function () {
    $code = TrialCode::factory()->exhausted()->create();

    redeemAs($this->user, $code->code)->assertStatus(422);

    expect(Subscription::count())->toBe(0);
});

it('refuses to let a referrer redeem their own code', function () {
    $code = TrialCode::factory()->referralFor($this->user)->create();

    redeemAs($this->user, $code->code)
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot redeem your own referral code.');
});

it('lets somebody else redeem a referral code', function () {
    $referrer = User::factory()->create();
    $code = TrialCode::factory()->referralFor($referrer)->create();

    redeemAs($this->user, $code->code)->assertCreated();

    expect(Subscription::where('trial_code_id', $code->id)->count())->toBe(1);
});

it('refuses a second trial for an organization that already had one', function () {
    // Otherwise a lapsed trial could be renewed forever with a fresh code.
    $first = TrialCode::factory()->create();
    redeemAs($this->user, $first->code)->assertCreated();

    Subscription::where('organization_id', $this->organization->id)
        ->update(['trial_ends_at' => now()->subDay(), 'status' => Subscription::STATUS_CANCELLED]);

    $second = TrialCode::factory()->create();

    redeemAs($this->user, $second->code)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your organization has already used a free trial.');
});

it('refuses a trial when the organization already pays', function () {
    Subscription::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->user->id,
        'plan_id' => $this->standard->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    $code = TrialCode::factory()->create();

    redeemAs($this->user, $code->code)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your organization already has an active subscription.');
});

/*
 * Creating an organization is no longer part of signing up — teams are a paid
 * capability — so the ordinary trial redeemer is one person with no
 * organization at all. That case used to be refused outright.
 */
it('grants a trial to a user with no organization', function () {
    $solo = User::factory()->create(['organization_id' => null]);
    $code = TrialCode::factory()->create();

    redeemAs($solo, $code->code)->assertCreated();

    expect(Subscription::where('user_id', $solo->id)->first())
        ->not->toBeNull()
        ->and(Subscription::where('user_id', $solo->id)->first()->organization_id)->toBeNull();
});

it('holds a user with no organization to one trial ever', function () {
    $solo = User::factory()->create(['organization_id' => null]);

    redeemAs($solo, TrialCode::factory()->create()->code)->assertCreated();

    // Lapse it: a spent trial must not be renewable by redeeming another code,
    // and without an organization to scope by, the user is what anchors that.
    Subscription::where('user_id', $solo->id)->update([
        'trial_ends_at' => now()->subDay(),
        'current_period_end' => now()->subDay(),
    ]);

    redeemAs($solo, TrialCode::factory()->create()->code)
        ->assertStatus(422)
        ->assertJsonPath('message', 'You have already used a free trial.');
});

it('does not let one user\'s trial block another with no organization', function () {
    $first = User::factory()->create(['organization_id' => null]);
    $second = User::factory()->create(['organization_id' => null]);
    $code = TrialCode::factory()->create(['max_redemptions' => 2]);

    redeemAs($first, $code->code)->assertCreated();
    redeemAs($second, $code->code)->assertCreated();
});

it('counts a redemption only once against a limited code', function () {
    $code = TrialCode::factory()->create(['max_redemptions' => 2]);

    redeemAs($this->user, $code->code)->assertCreated();

    $other = User::factory()->memberOf(Organization::factory()->create())->create();
    redeemAs($other, $code->code)->assertCreated();

    expect($code->fresh()->redeemed_count)->toBe(2);

    $third = User::factory()->memberOf(Organization::factory()->create())->create();
    redeemAs($third, $code->code)->assertStatus(422);
});

it('previews a code without claiming it', function () {
    $code = TrialCode::factory()->create(['trial_days' => 30, 'plan_id' => $this->firm->id]);

    test()->signInAs($this->user)
        ->getJson('/api/trial/code?code='.$code->code)
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('trial_days', 30)
        ->assertJsonPath('plan', 'Firm');

    expect($code->fresh()->redeemed_count)->toBe(0)
        ->and(Subscription::count())->toBe(0);
});

it('does not reveal why an unusable code failed when previewing', function () {
    // A precise reason here would let anyone enumerate valid codes.
    $code = TrialCode::factory()->exhausted()->create();

    test()->signInAs($this->user)
        ->getJson('/api/trial/code?code='.$code->code)
        ->assertNotFound()
        ->assertJsonPath('valid', false);
});

it('reports days remaining on the subscription payload', function () {
    $code = TrialCode::factory()->create(['trial_days' => 14]);

    redeemAs($this->user, $code->code)->assertCreated();

    $days = test()->signInAs($this->user)
        ->getJson('/api/subscription')
        ->assertOk()
        ->json('data.trial.days_remaining');

    expect($days)->toBeGreaterThan(12)->toBeLessThanOrEqual(14);
});

it('lets only one redemption through when two race for the last slot', function () {
    $code = TrialCode::factory()->create(['max_redemptions' => 1]);
    $other = User::factory()->memberOf(Organization::factory()->create())->create();

    $redeemer = app(TrialRedeemer::class);
    $redeemer->redeem($this->user, $code->code);

    expect(fn () => $redeemer->redeem($other, $code->code))
        ->toThrow(TrialRedemptionException::class);

    expect($code->fresh()->redeemed_count)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Trial exhaustion
|--------------------------------------------------------------------------
|
| A trial ends on whichever comes first: the date, or the plan's message
| allowance. The allowance is counted across the organization and never
| becomes overage — there is no payment method behind a trial to bill.
|
*/

function startTrialFor(User $user, int $messageCap, ?int $overagePrice = null): Subscription
{
    $plan = Plan::factory()->create([
        'sort_order' => 0,
        'overage_price' => $overagePrice,
        'limits' => ['active_cases' => null, 'documents_uploaded' => null, 'messages_used' => $messageCap],
    ]);

    $code = TrialCode::factory()->create(['plan_id' => $plan->id, 'trial_days' => 14]);

    app(TrialRedeemer::class)->redeem($user, $code->code);

    return $user->fresh()->subscription;
}

it('ends the trial once the message allowance is spent', function () {
    startTrialFor($this->user, messageCap: 3);

    foreach (range(1, 3) as $ignored) {
        PlanLimits::consumeMessage($this->user->fresh());
    }

    $subscription = $this->user->fresh()->subscription;

    expect($subscription->onTrial())->toBeFalse()
        ->and($subscription->isActive())->toBeFalse()
        ->and($subscription->trial_ends_at->isFuture())->toBeFalse();
});

it('keeps the trial alive while allowance remains', function () {
    startTrialFor($this->user, messageCap: 3);

    PlanLimits::consumeMessage($this->user->fresh());

    expect($this->user->fresh()->subscription->onTrial())->toBeTrue();
});

it('refuses the next message once the trial allowance is gone', function () {
    startTrialFor($this->user, messageCap: 2);

    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($this->user->fresh());

    expect(fn () => PlanLimits::consumeMessage($this->user->fresh()))
        ->toThrow(HttpResponseException::class);
});

it('never bills trial overage even when the plan prices it', function () {
    // A paid plan would start accruing overage here; a trial has no payment
    // method behind it, so the allowance is a wall rather than a meter.
    startTrialFor($this->user, messageCap: 2, overagePrice: 900);

    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($this->user->fresh());

    expect(fn () => PlanLimits::consumeMessage($this->user->fresh()))
        ->toThrow(HttpResponseException::class);

    expect($this->user->fresh()->usageCounterForCurrentPeriod()->messages_overage)->toBe(0);
});

it('counts the trial allowance across the whole organization', function () {
    // Otherwise inviting members multiplies the free messages by headcount.
    startTrialFor($this->user, messageCap: 4);

    $colleague = User::factory()->memberOf($this->organization)->create();

    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($colleague->fresh());
    PlanLimits::consumeMessage($colleague->fresh());

    expect($this->user->fresh()->subscription->onTrial())->toBeFalse();
});

it('reports organization-wide usage on the trial meter', function () {
    startTrialFor($this->user, messageCap: 10);

    $colleague = User::factory()->memberOf($this->organization)->create();
    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($colleague->fresh());

    test()->signInAs($this->user)
        ->getJson('/api/subscription')
        ->assertOk()
        ->assertJsonPath('data.usage.messages.used', 2);
});

it('leaves paid plans counting per seat and accruing overage', function () {
    // The trial rules must not leak into paid billing.
    $plan = Plan::factory()->create([
        'overage_price' => 900,
        'limits' => ['active_cases' => null, 'documents_uploaded' => null, 'messages_used' => 1],
    ]);

    Subscription::factory()->create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->user->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    PlanLimits::consumeMessage($this->user->fresh());
    PlanLimits::consumeMessage($this->user->fresh());

    expect($this->user->fresh()->usageCounterForCurrentPeriod()->messages_overage)->toBe(1);
});

it('tells a user their trial ran out of messages rather than to just subscribe', function () {
    startTrialFor($this->user, messageCap: 1);

    PlanLimits::consumeMessage($this->user->fresh());

    test()->signInAs($this->user->fresh())
        ->getJson('/api/conversations')
        ->assertStatus(402)
        ->assertJsonPath('message', 'Your free trial has used all of its messages. Subscribe to a plan to keep going.');
});

it('distinguishes a trial that ran out of days from one that ran out of messages', function () {
    startTrialFor($this->user, messageCap: 100);

    $this->user->fresh()->subscription->update(['trial_ends_at' => now()->subMinute()]);

    test()->signInAs($this->user->fresh())
        ->getJson('/api/conversations')
        ->assertStatus(402)
        ->assertJsonPath('message', 'Your free trial has ended. Subscribe to a plan to keep going.');
});

it('still tells a never-subscribed account to pick a plan', function () {
    test()->signInAs($this->user)
        ->getJson('/api/conversations')
        ->assertStatus(402)
        ->assertJsonPath('message', 'Subscribe to a plan to access Saligan.ai.');
});
