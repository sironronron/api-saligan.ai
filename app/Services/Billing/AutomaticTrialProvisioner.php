<?php

namespace App\Services\Billing;

use App\Exceptions\AutomaticTrialException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Grants the one no-card trial available to a user, started on demand from
 * the plan selector (POST /api/subscription/trial).
 *
 * The operation is idempotent because the plan selector can be submitted
 * more than once while the browser initializes.
 */
final class AutomaticTrialProvisioner
{
    /**
     * Provision the user's automatic trial, or return null when the trial plan
     * has not been seeded yet.
     *
     * @throws AutomaticTrialException when the account is not eligible
     */
    public function provision(User $user): ?Subscription
    {
        $plan = Plan::query()->where('slug', Plan::SLUG_TRIAL)->first();

        if ($plan === null) {
            return null;
        }

        $lock = Cache::lock("subscription.automatic-trial.{$user->id}", 30);

        if (! $lock->get()) {
            throw new AutomaticTrialException('A free trial is already being set up. Please try again.');
        }

        try {
            return DB::transaction(function () use ($user, $plan): Subscription {
                $user = $user->fresh(['organization']) ?? $user;

                if ($user->organization_id !== null) {
                    throw new AutomaticTrialException('Your workspace already controls its subscription.');
                }

                $existing = $user->subscriptions()
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ($existing->onTrial()) {
                        return $existing->load('plan');
                    }

                    if ($existing->trial_ends_at !== null) {
                        throw new AutomaticTrialException('You have already used your free trial. Subscribe to keep going.');
                    }

                    throw new AutomaticTrialException('You already have a subscription. Choose a plan to change it.');
                }

                $now = now();
                $endsAt = $now->copy()->addDays((int) config('saligan.trials.automatic_days', 14));

                return Subscription::create([
                    'organization_id' => null,
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'interval' => Plan::INTERVAL_MONTHLY,
                    'status' => Subscription::STATUS_TRIALING,
                    'trial_ends_at' => $endsAt,
                    'current_period_start' => $now,
                    'current_period_end' => $endsAt,
                    'seats_purchased' => 1,
                    'price_per_seat' => 0,
                ])->load('plan');
            });
        } finally {
            $lock->release();
        }
    }
}
