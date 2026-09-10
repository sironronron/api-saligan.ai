<?php

namespace App\Support;

use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\TrialWarner;
use Illuminate\Database\Eloquent\Builder;

class PlanLimits
{
    public const MESSAGE_KEY = 'messages_used';

    /**
     * Whether the user can currently use the product (paid active
     * subscription, or admin).
     */
    public static function hasActiveAccess(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        // The subscription resolves through the organization, so a suspended
        // member would otherwise pass every billing check their colleagues do.
        if ($user->isSuspended()) {
            return false;
        }

        $subscription = $user->subscription;

        return $subscription !== null && $subscription->isActive();
    }

    /**
     * Abort with a 402 when the user has no active subscription, or a 403 when
     * their membership is suspended.
     */
    public static function ensureActiveAccess(User $user): void
    {
        if (self::hasActiveAccess($user)) {
            return;
        }

        // Not an upgrade prompt: their organization's plan is current, and the
        // thing standing between them and the product is an administrator.
        if (! $user->is_admin && $user->isSuspended()) {
            abort(SuspendedResponse::make());
        }

        abort(self::upgradeResponse(self::noAccessMessage($user)));
    }

    /**
     * Why the user cannot get in, phrased for them.
     *
     * Someone whose trial just ran out is told that, rather than being shown
     * the same "subscribe to a plan" line a brand-new account sees — they did
     * subscribe, in the only way a trial allows, and the difference is the
     * whole reason they are being asked to pay now.
     */
    protected static function noAccessMessage(User $user): string
    {
        $subscription = $user->subscription;

        if ($subscription?->status !== Subscription::STATUS_TRIALING) {
            return 'Subscribe to a plan to access Saligan.ai.';
        }

        $limit = self::limitFor($user, self::MESSAGE_KEY);

        // Distinguishes the two ways a trial ends. Both are over, but only one
        // of them is worth telling someone they still had days left for.
        if ($limit !== null && self::trialUsed($user, self::MESSAGE_KEY) >= $limit) {
            return 'Your free trial has used all of its messages. Subscribe to a plan to keep going.';
        }

        return 'Your free trial has ended. Subscribe to a plan to keep going.';
    }

    /**
     * The numeric limit for a usage key, or null when unlimited.
     */
    public static function limitFor(User $user, string $key): ?int
    {
        $plan = $user->subscription?->plan;

        if ($plan === null || ! is_array($plan->limits)) {
            return null;
        }

        return $plan->limits[$key] ?? null;
    }

    /**
     * The current usage for a usage key in the current period.
     */
    public static function used(User $user, string $key): int
    {
        $counter = $user->usageCounterForCurrentPeriod();

        return $counter->{$key} ?? 0;
    }

    /**
     * Increment usage for a usage key in the current period.
     */
    public static function increment(User $user, string $key): void
    {
        $counter = $user->usageCounterForCurrentPeriod();
        $counter->increment($key);
    }

    /**
     * Record one AI message for the user.
     *
     * Allowances are prepaid: messages within the cap are counted normally,
     * and once the cap is reached the turn is refused. There is deliberately
     * no post-pay overage — an overage counter was accrued with no collection
     * path behind it, so it could only ever become displayed debt. A plan row
     * that still carries an `overage_price` is treated the same as one without:
     * the cap is a wall, not a meter.
     *
     * On a trial: the cap is counted across the organization and is the end of
     * the trial rather than the start of anything billable.
     */
    public static function consumeMessage(User $user): void
    {
        self::ensureActiveAccess($user);

        $counter = $user->usageCounterForCurrentPeriod();

        $limit = self::limitFor($user, self::MESSAGE_KEY);
        $subscription = $user->subscription;

        if ($user->is_admin || $limit === null) {
            $counter->increment(self::MESSAGE_KEY);

            return;
        }

        // A trial's allowance is the whole point of the trial, so it is counted
        // across the organization rather than per seat — otherwise inviting
        // members multiplies the free messages by the headcount. It also never
        // spills into overage: a trial has no payment method behind it.
        if ($subscription !== null && $subscription->onTrial()) {
            self::consumeTrialMessage($user, $subscription, $counter, $limit);

            return;
        }

        if ($counter->messages_used < $limit) {
            $counter->increment(self::MESSAGE_KEY);

            return;
        }

        abort(self::upgradeResponse("You have used this month's message allowance. Upgrade your plan for a larger allowance, or continue when allowances reset next month."));
    }

    /**
     * Give one AI message back to the user.
     *
     * `consumeMessage()` charges before any AI work runs, so a turn that
     * delivered nothing — the provider failed before producing output, the
     * client disconnected before anything was persisted — must hand the charge
     * back, or failures silently eat the allowance. Only call this on paths
     * where no assistant reply was persisted; a turn that persisted even a
     * partial reply delivered value and keeps its charge.
     */
    public static function refundMessage(User $user): void
    {
        $counter = $user->usageCounterForCurrentPeriod();

        if ($counter->messages_used > 0) {
            $counter->decrement(self::MESSAGE_KEY);

            return;
        }

        // Historical balances from before overage was removed: prefer to unwind
        // the current counter first, and only then touch legacy debt.
        if ($counter->messages_overage > 0) {
            $counter->decrement('messages_overage');
        }
    }

    /**
     * Spend one message against a trial's organization-wide allowance, ending
     * the trial as soon as it is exhausted.
     *
     * Ending it here rather than waiting for a scheduled sweep means the very
     * next request is already gated: `trial_ends_at` moves to now, so
     * {@see Subscription::onTrial()} turns false and every access check follows
     * without needing to know about message counts.
     */
    protected static function consumeTrialMessage(
        User $user,
        Subscription $subscription,
        UsageCounter $counter,
        int $limit,
    ): void {
        if (self::trialUsed($user, self::MESSAGE_KEY) >= $limit) {
            self::endTrial($subscription);

            abort(self::upgradeResponse(
                'Your free trial has used all of its messages. Subscribe to a plan to keep going.',
            ));
        }

        $counter->increment(self::MESSAGE_KEY);

        // Re-read after the increment: this message may have been the last one.
        $used = self::trialUsed($user, self::MESSAGE_KEY);

        if ($used >= $limit) {
            self::endTrial($subscription);

            return;
        }

        app(TrialWarner::class)->warnIfMessagesRunningLow($subscription, $used, $limit);
    }

    /**
     * Close a trial out immediately, leaving the row in place so the
     * organization keeps its history and cannot start a second trial.
     */
    protected static function endTrial(Subscription $subscription): void
    {
        $subscription->forceFill([
            'trial_ends_at' => now(),
            'current_period_end' => now(),
        ])->save();
    }

    /**
     * Total usage for a key across every member of the user's organization in
     * the current period. Falls back to the user's own counter when they are
     * not in an organization.
     *
     * Only active memberships count: suspended or merely invited members can
     * neither spend the allowance nor should their past spend keep counting
     * against the members who remain.
     */
    public static function organizationUsed(User $user, string $key): int
    {
        $organizationId = $user->organization_id;

        if ($organizationId === null) {
            return self::used($user, $key);
        }

        return (int) UsageCounter::query()
            ->where('period_key', UsageCounter::currentPeriodKey())
            ->whereIn('user_id', self::activeMemberIds($organizationId))
            ->sum($key);
    }

    /**
     * Total trial usage for a key across the organization since the trial
     * began — not just in the current calendar month.
     *
     * Counters reset on the calendar month while a 14-day trial can straddle
     * two of them, so summing only the current period would hand a trial that
     * crosses a month boundary a second allowance. The trial's start is the
     * subscription row's creation, which is when the trial was granted.
     */
    public static function trialUsed(User $user, string $key): int
    {
        $organizationId = $user->organization_id;

        if ($organizationId === null) {
            return self::used($user, $key);
        }

        $since = $user->subscription?->created_at ?? now();

        return (int) UsageCounter::query()
            ->where('period_key', '>=', $since->format('Y-m'))
            ->whereIn('user_id', self::activeMemberIds($organizationId))
            ->sum($key);
    }

    /**
     * @return Builder<User>
     */
    protected static function activeMemberIds(int $organizationId)
    {
        return User::query()
            ->where('organization_id', $organizationId)
            ->where('org_status', User::ORG_STATUS_ACTIVE)
            ->select('id');
    }

    /**
     * Abort with a 402 when the user cannot use the feature this cycle.
     *
     * Allowances reset every calendar month (`UsageCounter::currentPeriodKey`),
     * not on the subscription anniversary — the message says so, so nobody
     * plans around a renewal date that does not move the counters.
     */
    public static function ensureCanUse(User $user, string $key): void
    {
        self::ensureActiveAccess($user);

        $limit = self::limitFor($user, $key);

        if ($limit === null || $user->is_admin) {
            return;
        }

        if (self::used($user, $key) >= $limit) {
            abort(self::upgradeResponse("You've reached this month's allowance. Allowances reset every calendar month — upgrade your plan for more room."));
        }
    }

    /**
     * A 402 JSON response carrying the flag the frontend keys off.
     */
    protected static function upgradeResponse(string $message)
    {
        return UpgradeResponse::make($message);
    }
}
