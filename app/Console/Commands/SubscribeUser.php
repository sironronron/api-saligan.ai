<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscribe:user {user : User id or email} {--plan= : Plan slug (starter, pro, firm)} {--interval=monthly : Billing interval (monthly, annual)}')]
#[Description('Manually grant a user an active subscription on a specific plan')]
class SubscribeUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = $this->resolveUser($this->argument('user'));

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $plan = $this->resolvePlan($this->option('plan'));

        if ($plan === null) {
            $this->error('Plan not found. Choose one of: '.Plan::query()->pluck('slug')->implode(', '));

            return self::FAILURE;
        }

        $interval = $this->option('interval') ?? Plan::INTERVAL_MONTHLY;

        $subscription = $this->grantSubscription($user, $plan, $interval);

        $this->info("Subscribed {$user->email} to {$plan->name} ({$interval}) [{$subscription->status}].");

        return self::SUCCESS;
    }

    /**
     * Look up the user by id or email.
     */
    protected function resolveUser(string $identifier): ?User
    {
        $user = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', $identifier)->first();

        if ($user === null) {
            return null;
        }

        $this->line("User: {$user->email} (id: {$user->id})");

        return $user;
    }

    /**
     * Look up the plan by slug, prompting when none was given.
     */
    protected function resolvePlan(?string $slug): ?Plan
    {
        $slugs = Plan::query()->orderBy('sort_order')->pluck('slug')->all();

        $slug ??= $this->choice('Which plan?', $slugs);

        $plan = Plan::query()->where('slug', $slug)->first();

        if ($plan === null) {
            return null;
        }

        $this->line("Plan: {$plan->name} ({$plan->slug})");

        return $plan;
    }

    /**
     * Create or move the user's current subscription to the given plan,
     * marking it active immediately so they get full access.
     */
    protected function grantSubscription(User $user, Plan $plan, string $interval): Subscription
    {
        $periodEnd = $interval === Plan::INTERVAL_ANNUAL
            ? now()->addYear()
            : now()->addMonth();

        $attributes = [
            'plan_id' => $plan->id,
            'organization_id' => $user->organization_id,
            'interval' => $interval,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => $periodEnd,
            'cancelled_at' => null,
            'paymongo_subscription_id' => null,
            'paymongo_customer_id' => null,
        ];

        $subscription = $user->subscription;

        if ($subscription !== null) {
            $subscription->update($attributes);
            $this->line('Updated the existing subscription.');
        } else {
            $subscription = $user->subscriptions()->create($attributes);
            $this->line('Created a new subscription.');
        }

        return $subscription;
    }
}
