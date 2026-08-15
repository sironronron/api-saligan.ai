<?php

namespace App\Services\Billing;

use App\Exceptions\TrialRedemptionException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a trial code into a trialing subscription for the redeemer's
 * organization.
 *
 * A trial is an ordinary subscription row with status `trialing`, so plan
 * limits, usage counters, and access checks all work through the existing
 * paths — nothing downstream needs to know a trial is different from a
 * paid plan except that it ends.
 */
class TrialRedeemer
{
    /**
     * Redeem a code for a user, returning the trialing subscription.
     *
     * @throws TrialRedemptionException when the code or the redeemer is ineligible
     */
    public function redeem(User $user, string $code): Subscription
    {
        // An organization is optional now: teams are a paid capability, so most
        // accounts redeeming a trial are a single person who has never created
        // one. Where there is an organization the trial belongs to it — one
        // trial for the whole firm, not one per colleague invited.
        $organization = $user->organization;

        // Everything from here re-reads under a row lock: two requests racing
        // the same last remaining redemption must not both succeed.
        return DB::transaction(function () use ($user, $code, $organization): Subscription {
            $trialCode = TrialCode::query()
                ->where('code', TrialCode::normalise($code))
                ->lockForUpdate()
                ->first();

            if ($trialCode === null) {
                throw new TrialRedemptionException('That code is not valid.');
            }

            $this->assertCodeUsable($trialCode);
            $this->assertRedeemerEligible($user, $trialCode, $organization?->id);

            $plan = $trialCode->plan ?? $this->defaultTrialPlan();

            if ($plan === null) {
                throw new TrialRedemptionException('No plan is available to trial right now.');
            }

            $endsAt = now()->addDays($trialCode->trial_days);

            $subscription = Subscription::create([
                'organization_id' => $organization?->id,
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'interval' => Plan::INTERVAL_MONTHLY,
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => $endsAt,
                'trial_code_id' => $trialCode->id,
                // A trial bills nothing, but the period bounds are what usage
                // counters and the billing UI read, so they are set as normal.
                'current_period_start' => now(),
                'current_period_end' => $endsAt,
                'seats_purchased' => 1,
                'price_per_seat' => 0,
            ]);

            $trialCode->increment('redeemed_count');

            return $subscription;
        });
    }

    /**
     * @throws TrialRedemptionException
     */
    protected function assertCodeUsable(TrialCode $code): void
    {
        if (! $code->is_active) {
            throw new TrialRedemptionException('That code is no longer active.');
        }

        if ($code->hasExpired()) {
            throw new TrialRedemptionException('That code has expired.');
        }

        if ($code->isExhausted()) {
            throw new TrialRedemptionException('That code has already been fully claimed.');
        }
    }

    /**
     * @param  int|string|null  $organizationId  Null for an account that has no
     *                                           organization, which is now the
     *                                           ordinary case rather than an error.
     *
     * @throws TrialRedemptionException
     */
    protected function assertRedeemerEligible(User $user, TrialCode $code, int|string|null $organizationId): void
    {
        // Self-referral would make a personal code a free trial generator for
        // its own owner.
        if ($code->owner_user_id !== null && $code->owner_user_id === $user->id) {
            throw new TrialRedemptionException('You cannot redeem your own referral code.');
        }

        // The trial belongs to whoever it was granted to: the firm when there
        // is one, the person when there is not. Scoping to the user in the
        // second case is what stops "one trial ever" from being defeated by
        // simply never creating an organization.
        $scope = fn () => $organizationId === null
            ? Subscription::query()->whereNull('organization_id')->where('user_id', $user->id)
            : Subscription::query()->where('organization_id', $organizationId);

        $existing = $scope()->orderByDesc('id')->first();

        if ($existing === null) {
            return;
        }

        $personal = $organizationId === null;

        if ($existing->isActive()) {
            throw new TrialRedemptionException(match (true) {
                $existing->onTrial() && $personal => 'You are already on a trial.',
                $existing->onTrial() => 'Your organization is already on a trial.',
                $personal => 'You already have an active subscription.',
                default => 'Your organization already has an active subscription.',
            });
        }

        // One trial ever. Without this, a lapsed trial could be renewed
        // indefinitely by redeeming another code.
        if ($scope()->whereNotNull('trial_ends_at')->exists()) {
            throw new TrialRedemptionException($personal
                ? 'You have already used a free trial.'
                : 'Your organization has already used a free trial.');
        }
    }

    /**
     * The plan a code trials on when it does not name one: the dedicated trial
     * plan, whose allowance is a quarter of Standard's.
     *
     * Falls back to the cheapest active plan where that plan has not been
     * seeded, so an unspecified code still grants something rather than
     * failing — and never silently grants the top tier.
     */
    protected function defaultTrialPlan(): ?Plan
    {
        return Plan::query()->where('slug', Plan::SLUG_TRIAL)->first()
            ?? Plan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->first();
    }
}
