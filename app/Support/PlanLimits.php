<?php

namespace App\Support;

use App\Models\Subscription;
use App\Models\User;

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

        $subscription = $user->subscription;

        return $subscription !== null && $subscription->isActive();
    }

    /**
     * Abort with a 402 when the user has no active subscription.
     */
    public static function ensureActiveAccess(User $user): void
    {
        if (self::hasActiveAccess($user)) {
            return;
        }

        abort(self::upgradeResponse('Subscribe to a plan to access Saligan.ai.'));
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
     * Record one AI message for the user. Messages within the plan's cap are
     * counted normally; once the cap is reached, messages are counted as
     * overage when the plan has an overage price, or blocked otherwise.
     */
    public static function consumeMessage(User $user): void
    {
        self::ensureActiveAccess($user);

        $counter = $user->usageCounterForCurrentPeriod();

        $limit = self::limitFor($user, self::MESSAGE_KEY);
        $plan = $user->subscription?->plan;

        if ($user->is_admin || $limit === null || $counter->messages_used < $limit) {
            $counter->increment(self::MESSAGE_KEY);

            return;
        }

        if ($plan?->overage_price !== null) {
            $counter->increment('messages_overage');

            return;
        }

        abort(self::upgradeResponse('Monthly message limit reached. Upgrade your plan or wait for your next billing cycle.'));
    }

    /**
     * Abort with a 402 when the user cannot use the feature this cycle.
     */
    public static function ensureCanUse(User $user, string $key): void
    {
        self::ensureActiveAccess($user);

        $limit = self::limitFor($user, $key);

        if ($limit === null || $user->is_admin) {
            return;
        }

        if (self::used($user, $key) >= $limit) {
            abort(self::upgradeResponse('Monthly limit reached. Upgrade your plan to continue.'));
        }
    }

    /**
     * A 402 JSON response carrying the flag the frontend keys off.
     */
    protected static function upgradeResponse(string $message)
    {
        return response()->json([
            'message' => $message,
            'upgrade_required' => true,
        ], 402);
    }
}
