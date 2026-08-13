<?php

namespace App\Services\Billing;

use App\Mail\TrialEndingMail;
use App\Models\Subscription;
use App\Notifications\TrialEnding;
use App\Support\PlanLimits;
use Illuminate\Support\Facades\Log;

/**
 * Sends the single "your trial is nearly up" warning.
 *
 * Both triggers land here — the nightly sweep for calendar days, and the
 * message counter for allowance — so the once-only rule is enforced in one
 * place rather than trusted to two callers that cannot see each other.
 */
class TrialWarner
{
    /**
     * Warn the owner of a trial, unless one has already been sent.
     *
     * Returns whether a warning actually went out, so the sweep can report a
     * meaningful count rather than the number of rows it looked at.
     */
    public function warn(Subscription $subscription, string $reason, int $remaining): bool
    {
        if (! $subscription->onTrial() || $subscription->trial_warned_at !== null) {
            return false;
        }

        $user = $subscription->user;

        if ($user === null) {
            return false;
        }

        // Stamped before sending, not after: a mail failure must not leave the
        // row eligible for a retry on every subsequent request or sweep tick.
        // Losing one warning is a far smaller problem than emailing in a loop.
        $subscription->forceFill(['trial_warned_at' => now()])->save();

        try {
            $user->notify(new TrialEnding($subscription, $reason, $remaining));
        } catch (\Throwable $e) {
            Log::warning('Trial warning email failed.', [
                'subscription_id' => $subscription->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Warn when the organization is close to spending its message allowance.
     */
    public function warnIfMessagesRunningLow(Subscription $subscription, int $used, int $limit): void
    {
        $threshold = (int) config('saligan.trials.warn_messages_remaining');

        if ($threshold <= 0) {
            return;
        }

        $remaining = $limit - $used;

        // Only on the crossing, and only while some allowance is left — the
        // exhaustion case is its own message, not a warning about the future.
        if ($remaining > 0 && $remaining <= $threshold) {
            $this->warn($subscription, TrialEndingMail::REASON_MESSAGES, $remaining);
        }
    }

    /**
     * Warn every trial inside the day threshold that has not been warned yet.
     *
     * @return int the number of warnings sent
     */
    public function sweepExpiringTrials(): int
    {
        $threshold = (int) config('saligan.trials.warn_days_remaining');

        if ($threshold <= 0) {
            return 0;
        }

        $sent = 0;

        Subscription::query()
            ->where('status', Subscription::STATUS_TRIALING)
            ->whereNull('trial_warned_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays($threshold))
            ->with(['user', 'plan'])
            ->chunkById(100, function ($subscriptions) use (&$sent): void {
                foreach ($subscriptions as $subscription) {
                    // A trial can also be close to its message cap; whichever
                    // number is smaller is the one worth putting in the email.
                    $reason = TrialEndingMail::REASON_DAYS;
                    $remaining = $subscription->trialDaysRemaining() ?? 0;

                    $limit = $subscription->user
                        ? PlanLimits::limitFor($subscription->user, PlanLimits::MESSAGE_KEY)
                        : null;

                    if ($limit !== null && $subscription->user !== null) {
                        $messagesLeft = $limit - PlanLimits::organizationUsed(
                            $subscription->user,
                            PlanLimits::MESSAGE_KEY,
                        );

                        if ($messagesLeft > 0 && $messagesLeft < $remaining) {
                            $reason = TrialEndingMail::REASON_MESSAGES;
                            $remaining = $messagesLeft;
                        }
                    }

                    if ($this->warn($subscription, $reason, $remaining)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
