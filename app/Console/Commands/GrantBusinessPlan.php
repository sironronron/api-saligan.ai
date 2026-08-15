<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Organizations\OrganizationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('plan:business
    {user : User id or email}
    {--org= : Organization name to create when the user has none}
    {--seats=1 : Seats the contract covers}
    {--price= : Contracted price per seat, in pesos (e.g. 2500)}
    {--interval=monthly : Billing interval (monthly, annual)}
    {--months= : Term length in months (defaults to the interval)}
    {--force : Skip the confirmation when the organization already has an active subscription}')]
#[Description('Put a user (and their organization) on the contract-priced Business plan')]
class GrantBusinessPlan extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OrganizationService $organizations): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $plan = Plan::query()->where('slug', Plan::SLUG_BUSINESS)->first();

        if ($plan === null) {
            $this->error('No Business plan exists yet. Run `php artisan db:seed --class=PlansSeeder` first.');

            return self::FAILURE;
        }

        $interval = (string) $this->option('interval');

        if (! in_array($interval, [Plan::INTERVAL_MONTHLY, Plan::INTERVAL_ANNUAL], true)) {
            $this->error('Interval must be monthly or annual.');

            return self::FAILURE;
        }

        if (! $this->confirmExistingSubscription($user)) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $organization = $this->resolveOrganization($user, $organizations);

        $subscription = $this->grantSubscription($user, $organization, $plan, $interval);

        $this->table(['Field', 'Value'], [
            ['User', "{$user->email} (id: {$user->id})"],
            ['Organization', "{$organization->name} (id: {$organization->id})"],
            ['Plan', "{$plan->name} ({$plan->slug})"],
            ['Seats', (string) $subscription->seats_purchased],
            ['Price per seat', $subscription->price_per_seat === null
                ? 'not recorded'
                : '₱'.number_format($subscription->price_per_seat / 100, 2)],
            ['Term', "{$interval}, until {$subscription->current_period_end->toDateString()}"],
            ['Status', $subscription->status],
        ]);

        $this->info("{$user->email} is now on {$plan->name}.");

        return self::SUCCESS;
    }

    /**
     * Look up the user by id or email.
     */
    protected function resolveUser(string $identifier): ?User
    {
        return ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', strtolower(trim($identifier)))->first();
    }

    /**
     * Ask before moving an organization that is already paying or trialling —
     * this rewrites the subscription row in place, and on a paid plan that
     * means the gateway subscription is left behind still billing.
     */
    protected function confirmExistingSubscription(User $user): bool
    {
        $current = $user->subscription;

        if ($current === null || ! $current->isActive() || $this->option('force')) {
            return true;
        }

        $this->warn("This account already has a {$current->status} subscription on "
            .($current->plan?->name ?? 'an unknown plan').'.');

        if ($current->gatewaySubscriptionId() !== null) {
            $this->warn('It has a live gateway subscription ('.$current->gatewaySubscriptionId().') — cancel that separately, or it keeps billing.');
        }

        return $this->confirm('Move it to Business anyway?', false);
    }

    /**
     * The organization the subscription hangs off: the user's own when they
     * already have one, otherwise a new organization created with them as its
     * owner. Subscriptions belong to the organization, so a Business account
     * without one would have nowhere to put its seats.
     */
    protected function resolveOrganization(User $user, OrganizationService $organizations): Organization
    {
        $existing = $user->organization;

        if ($existing !== null) {
            if ($this->option('org') !== null && $this->option('org') !== $existing->name) {
                $this->warn("Keeping the existing organization [{$existing->name}]; --org was ignored.");
            }

            return $existing;
        }

        $name = (string) ($this->option('org') ?: $this->defaultOrganizationName($user));

        $organization = $organizations->createOrganization($name, $user);

        $this->line("Created organization [{$organization->name}] with {$user->email} as owner.");

        return $organization;
    }

    /**
     * A workable organization name when the contract did not give one: the
     * user's own name, falling back to the local part of their email.
     */
    protected function defaultOrganizationName(User $user): string
    {
        $name = trim((string) $user->name);

        if ($name !== '') {
            return "{$name}'s organization";
        }

        return Str::of($user->email)->before('@')->headline()->toString();
    }

    /**
     * Create or move the organization's subscription onto the Business plan,
     * active immediately. The contracted seats and per-seat price are written
     * onto the subscription rather than the plan: the plan carries no price
     * precisely because every Business contract sets its own.
     */
    protected function grantSubscription(User $user, Organization $organization, Plan $plan, string $interval): Subscription
    {
        $months = $this->option('months') !== null
            ? max(1, (int) $this->option('months'))
            : ($interval === Plan::INTERVAL_ANNUAL ? 12 : 1);

        $price = $this->option('price');

        $attributes = [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'interval' => $interval,
            'status' => Subscription::STATUS_ACTIVE,
            'seats_purchased' => max(1, (int) $this->option('seats')),
            'price_per_seat' => $price === null ? null : (int) round((float) $price * 100),
            'current_period_start' => now()->startOfDay(),
            'current_period_end' => now()->addMonths($months),
            // Invoiced off-platform, so no gateway holds this subscription.
            // `trial_ends_at` is deliberately left as it was: an organization
            // converting off a trial has still used its one trial, and that is
            // the column TrialRedeemer reads to know it.
            'cancelled_at' => null,
            'paymongo_subscription_id' => null,
            'paymongo_customer_id' => null,
            'lemonsqueezy_subscription_id' => null,
            'lemonsqueezy_customer_id' => null,
        ];

        return DB::transaction(function () use ($user, $organization, $attributes): Subscription {
            // The organization's row when it has one, otherwise any row the
            // user bought before they had an organization — moving that one
            // over rather than leaving it orphaned beside the new one.
            $subscription = $organization->subscription
                ?? $user->subscriptions()->latest('id')->first();

            if ($subscription !== null) {
                $subscription->update($attributes);
                $this->line('Updated the existing subscription.');

                return $subscription->fresh();
            }

            $this->line('Created a new subscription.');

            return Subscription::create($attributes);
        });
    }
}
