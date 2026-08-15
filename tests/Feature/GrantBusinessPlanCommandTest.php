<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->business = Plan::factory()->business()->create();
});

it('creates an organization and puts the user on the Business plan', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $this->artisan('plan:business', [
        'user' => $user->email,
        '--org' => 'Acme Legal Aid',
        '--seats' => 12,
        '--price' => 2500,
    ])->assertExitCode(0);

    $user->refresh();
    $organization = Organization::where('name', 'Acme Legal Aid')->firstOrFail();

    expect($user->organization_id)->toBe($organization->id)
        ->and($user->org_role)->toBe(User::ORG_ROLE_OWNER)
        ->and($user->org_status)->toBe(User::ORG_STATUS_ACTIVE);

    $subscription = $organization->subscription;

    expect($subscription)->not->toBeNull()
        ->and($subscription->plan_id)->toBe($this->business->id)
        ->and($subscription->user_id)->toBe($user->id)
        ->and($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->seats_purchased)->toBe(12)
        // Pesos in, centavos stored, matching every other price in the schema.
        ->and($subscription->price_per_seat)->toBe(250000)
        ->and($subscription->isActive())->toBeTrue()
        ->and($subscription->gatewaySubscriptionId())->toBeNull();
});

it('names the organization after the user when none is given', function () {
    $user = User::factory()->create(['organization_id' => null, 'name' => 'Rene Santos']);

    $this->artisan('plan:business', ['user' => $user->email])->assertExitCode(0);

    expect($user->refresh()->organization->name)->toBe("Rene Santos's organization");
});

it('keeps the existing organization instead of creating a second one', function () {
    $organization = Organization::factory()->create(['name' => 'Existing Firm']);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'org_role' => User::ORG_ROLE_OWNER,
        'org_status' => User::ORG_STATUS_ACTIVE,
    ]);

    $this->artisan('plan:business', [
        'user' => $user->email,
        '--org' => 'Ignored Name',
    ])->assertExitCode(0);

    expect(Organization::count())->toBe(1)
        ->and($user->refresh()->organization_id)->toBe($organization->id)
        ->and($organization->subscription->plan_id)->toBe($this->business->id);
});

it('accepts the user id instead of the email', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $this->artisan('plan:business', ['user' => (string) $user->id])->assertExitCode(0);

    expect($user->refresh()->subscription->plan->slug)->toBe(Plan::SLUG_BUSINESS);
});

it('runs an annual term for twelve months by default', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $this->artisan('plan:business', [
        'user' => $user->email,
        '--interval' => Plan::INTERVAL_ANNUAL,
    ])->assertExitCode(0);

    $subscription = $user->refresh()->subscription;

    expect($subscription->interval)->toBe(Plan::INTERVAL_ANNUAL)
        ->and($subscription->current_period_end->toDateString())->toBe(now()->addYear()->toDateString());
});

it('honours an explicit term in months', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $this->artisan('plan:business', [
        'user' => $user->email,
        '--months' => 6,
    ])->assertExitCode(0);

    expect($user->refresh()->subscription->current_period_end->toDateString())
        ->toBe(now()->addMonths(6)->toDateString());
});

it('moves an existing subscription rather than leaving a second row behind', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'org_role' => User::ORG_ROLE_OWNER,
        'org_status' => User::ORG_STATUS_ACTIVE,
    ]);
    $pro = Plan::factory()->pro()->create();

    Subscription::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'plan_id' => $pro->id,
        'status' => Subscription::STATUS_CANCELLED,
        'cancelled_at' => now()->subDay(),
    ]);

    $this->artisan('plan:business', ['user' => $user->email])->assertExitCode(0);

    expect(Subscription::count())->toBe(1)
        ->and($organization->refresh()->subscription->plan_id)->toBe($this->business->id)
        ->and($organization->subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($organization->subscription->cancelled_at)->toBeNull();
});

it('adopts a pre-organization subscription row when it creates the organization', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $standard = Plan::factory()->standard()->create();

    Subscription::factory()->for($user)->create([
        'organization_id' => null,
        'plan_id' => $standard->id,
        'status' => Subscription::STATUS_CANCELLED,
    ]);

    $this->artisan('plan:business', ['user' => $user->email])->assertExitCode(0);

    expect(Subscription::count())->toBe(1)
        ->and($user->refresh()->subscription->organization_id)->toBe($user->organization_id)
        ->and($user->subscription->plan_id)->toBe($this->business->id);
});

it('asks before overwriting an active subscription', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'org_role' => User::ORG_ROLE_OWNER,
        'org_status' => User::ORG_STATUS_ACTIVE,
    ]);
    $pro = Plan::factory()->pro()->create();

    Subscription::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'plan_id' => $pro->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    $this->artisan('plan:business', ['user' => $user->email])
        ->expectsConfirmation('Move it to Business anyway?', 'no')
        ->assertExitCode(1);

    expect($organization->refresh()->subscription->plan_id)->toBe($pro->id);
});

it('skips the confirmation with --force', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'org_role' => User::ORG_ROLE_OWNER,
        'org_status' => User::ORG_STATUS_ACTIVE,
    ]);
    $pro = Plan::factory()->pro()->create();

    Subscription::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'plan_id' => $pro->id,
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    $this->artisan('plan:business', ['user' => $user->email, '--force' => true])
        ->assertExitCode(0);

    expect($organization->refresh()->subscription->plan_id)->toBe($this->business->id);
});

it('leaves an organization that has trialled unable to trial again', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'org_role' => User::ORG_ROLE_OWNER,
        'org_status' => User::ORG_STATUS_ACTIVE,
    ]);
    $trialPlan = Plan::factory()->trial()->create();

    Subscription::factory()->for($user)->create([
        'organization_id' => $organization->id,
        'plan_id' => $trialPlan->id,
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(5),
    ]);

    $this->artisan('plan:business', ['user' => $user->email, '--force' => true])
        ->assertExitCode(0);

    $subscription = $organization->refresh()->subscription;

    // `trial_ends_at` is what TrialRedeemer reads to know a trial was used, so
    // converting to Business must not wipe it and hand back a second trial.
    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->onTrial())->toBeFalse()
        ->and($subscription->trial_ends_at)->not->toBeNull();
});

it('fails when the user does not exist', function () {
    $this->artisan('plan:business', ['user' => 'nobody@example.com'])->assertExitCode(1);
});

it('fails when no Business plan has been seeded', function () {
    $this->business->delete();
    $user = User::factory()->create();

    $this->artisan('plan:business', ['user' => $user->email])->assertExitCode(1);
});

it('rejects an interval that is neither monthly nor annual', function () {
    $user = User::factory()->create();

    $this->artisan('plan:business', ['user' => $user->email, '--interval' => 'weekly'])
        ->assertExitCode(1);
});
