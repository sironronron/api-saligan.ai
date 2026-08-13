<?php

use App\Models\Plan;
use App\Models\TrialCode;
use App\Models\User;

it('mints a personal referral code for a user', function () {
    $user = User::factory()->create(['name' => 'Maria Santos']);

    $this->artisan('referral:code', ['owner' => $user->email])
        ->assertExitCode(0);

    $code = TrialCode::query()->where('owner_user_id', $user->id)->firstOrFail();

    expect($code->code)->toStartWith('MARIA-')
        ->and($code->trial_days)->toBe(14)
        ->and($code->max_redemptions)->toBeNull()
        ->and($code->owner_user_id)->toBe($user->id)
        ->and($code->note)->toBe('Personal referral code');
});

it('accepts the user id instead of the email', function () {
    $user = User::factory()->create();

    $this->artisan('referral:code', ['owner' => $user->id])->assertExitCode(0);

    expect(TrialCode::query()->where('owner_user_id', $user->id)->exists())->toBeTrue();
});

it('honours a custom trial length', function () {
    $user = User::factory()->create();

    $this->artisan('referral:code', ['owner' => $user->email, '--days' => 30])
        ->assertExitCode(0);

    expect(TrialCode::query()->where('owner_user_id', $user->id)->first()->trial_days)->toBe(30);
});

it('names a plan to trial on', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()->create(['slug' => 'firm']);

    $this->artisan('referral:code', ['owner' => $user->email, '--plan' => 'firm'])
        ->assertExitCode(0);

    expect(TrialCode::query()->where('owner_user_id', $user->id)->first()->plan_id)->toBe($plan->id);
});

it('fails with a non-zero exit code when the user is not found', function () {
    $this->artisan('referral:code', ['owner' => 'missing@example.com'])
        ->assertExitCode(1);
});

it('fails with a non-zero exit code when the plan is not found', function () {
    $user = User::factory()->create();

    $this->artisan('referral:code', ['owner' => $user->email, '--plan' => 'nonexistent'])
        ->assertExitCode(1);
});
