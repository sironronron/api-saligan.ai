<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\Billing\PaymongoClient;
use App\Support\PlanFeatures;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PlansSeeder extends Seeder
{
    /**
     * The paid ladder, and the reasoning behind every number on it.
     *
     * Messages are the cost driver, and what a message costs depends on which
     * model writes it: ₱1.75 on the base model against ₱3.49 on the frontier
     * one, measured by EarningsModel at a 70% prompt-cache hit rate. That
     * halving is the whole shape of this ladder. Standard buys volume on the
     * base model; Pro buys the frontier model, deeper retrieval, and scan
     * reading, and therefore buys fewer messages per peso — not because it is
     * worse value, but because its messages genuinely cost twice as much to
     * serve. Firm buys the same messages as Pro for a team, and sells more
     * seats at less than a Pro subscription each.
     *
     * Every plan is sized to hold roughly 65% gross margin at a 70% cache-hit
     * rate and to stay profitable if the cache never warms at all. Verify with
     * `artisan costing:earnings --cache-hit-rate=0.7` after changing anything
     * here — but note that command reads `messages_used` as a per-account
     * figure, so it understates Firm, whose allowance is per seat.
     */
    public function run(): void
    {
        // Standard's allowances, named once: the trial is defined as a quarter
        // of them rather than as its own set of numbers, so the two can never
        // drift into a trial that is more or less generous than intended.
        $standardLimits = [
            'active_cases' => 15,
            'documents_uploaded' => 25,
            'messages_used' => 240,
        ];

        // What every paid plan carries. Reading the template library is free
        // to everyone; drafting from one, exporting the result, and checking
        // the law against the live web are what a subscription buys.
        $baseFeatures = [
            PlanFeatures::DRAFTING,
            PlanFeatures::EXPORTS,
            PlanFeatures::WEB_SEARCH,
        ];

        $plans = [
            [
                // Seeded inactive so it never appears on the pricing page or in
                // checkout — only {@see \App\Services\Billing\TrialRedeemer}
                // reaches it.
                'slug' => Plan::SLUG_TRIAL,
                'name' => 'Free trial',
                'price' => 0,
                'price_annual' => 0,
                'overage_price' => null,
                'included_seats' => 1,
                'seat_price' => null,
                'sort_order' => 0,
                'is_active' => false,
                // A quarter of Standard across every allowance: enough to run a
                // real matter end to end and see cited answers, not enough to be
                // a substitute for paying.
                'limits' => array_map(
                    fn (int $limit): int => (int) ceil($limit / 4),
                    $standardLimits,
                ),
                // Exactly Standard's capabilities. A trial that hides features
                // is trialling a product nobody is being asked to buy — and
                // like Standard, it is answered by the base model.
                'features' => $baseFeatures,
            ],
            [
                'slug' => Plan::SLUG_STANDARD,
                'name' => 'Standard',
                'price' => 150000,
                'price_annual' => 1494000,
                // Capped rather than metered, deliberately. Standard is where
                // someone is still working out what they need; a bill that can
                // grow while they do that is the wrong thing to hand them.
                'overage_price' => null,
                'included_seats' => 1,
                'seat_price' => null,
                'sort_order' => 1,
                'limits' => $standardLimits,
                'features' => $baseFeatures,
            ],
            [
                'slug' => Plan::SLUG_PRO,
                'name' => 'Pro',
                'price' => 350000,
                'price_annual' => 3490000,
                // Clears the ~₱3.49 marginal cost with room to spare. Priced at
                // cost, as it once was, every extra message was a rounding error
                // against the support burden it carries.
                'overage_price' => 900,
                'included_seats' => 1,
                'seat_price' => null,
                'sort_order' => 2,
                'limits' => [
                    'active_cases' => null,
                    'documents_uploaded' => 100,
                    'messages_used' => 300,
                ],
                'features' => [
                    ...$baseFeatures,
                    PlanFeatures::FRONTIER_MODEL,
                    PlanFeatures::DEEP_RESEARCH,
                    PlanFeatures::DOCUMENT_INTELLIGENCE,
                    PlanFeatures::INTEGRATIONS,
                ],
            ],
            [
                'slug' => Plan::SLUG_FIRM,
                'name' => 'Firm',
                'price' => 1100000,
                'price_annual' => 10990000,
                'overage_price' => 850,
                // Three people for ₱11,000, against ₱10,500 for three separate
                // Pro accounts that cannot share a matter between them. The
                // fourth seat onwards costs less than a Pro subscription.
                'included_seats' => 3,
                'seat_price' => 320000,
                'sort_order' => 3,
                // Allowances are counted per seat (see PlanLimits::consumeMessage),
                // so this is 300 messages each, not 300 shared between them.
                'limits' => [
                    'active_cases' => null,
                    'documents_uploaded' => null,
                    'messages_used' => 300,
                ],
                'features' => [
                    ...$baseFeatures,
                    PlanFeatures::FRONTIER_MODEL,
                    PlanFeatures::DEEP_RESEARCH,
                    PlanFeatures::DOCUMENT_INTELLIGENCE,
                    PlanFeatures::INTEGRATIONS,
                    PlanFeatures::TEAMS,
                    PlanFeatures::SUPPORT_24_7,
                ],
            ],
            [
                // Sold by conversation, not by card. Organizations at this size
                // negotiate seats, allowance, and term, so the row carries no
                // list price and no allowance of its own — the contract sets
                // both, and `plan:business` writes them onto the subscription.
                // Active so it is listed, `contact_sales` so checkout refuses
                // it and the pricing page asks for a conversation instead.
                'slug' => Plan::SLUG_BUSINESS,
                'name' => 'Business',
                'price' => 0,
                'price_annual' => 0,
                'overage_price' => null,
                'included_seats' => 1,
                // Seat terms are agreed, not listed, so there is no number to
                // print — the same reason the row carries no price.
                'seat_price' => null,
                'sort_order' => 4,
                'contact_sales' => true,
                'limits' => [
                    'active_cases' => null,
                    'documents_uploaded' => null,
                    'messages_used' => null,
                ],
                // Everything Firm has, plus what only a contract can carry: the
                // account set up and the team trained by us.
                'features' => [
                    ...$baseFeatures,
                    PlanFeatures::FRONTIER_MODEL,
                    PlanFeatures::DEEP_RESEARCH,
                    PlanFeatures::DOCUMENT_INTELLIGENCE,
                    PlanFeatures::INTEGRATIONS,
                    PlanFeatures::TEAMS,
                    PlanFeatures::GUIDED_SETUP,
                    PlanFeatures::TEAM_TRAINING,
                    PlanFeatures::SUPPORT_24_7,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            $record = Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                // Union, not merge: a plan above that sets `is_active` itself
                // keeps its own value.
                $plan + ['currency' => 'PHP', 'interval' => Plan::INTERVAL_MONTHLY, 'is_active' => true],
            );

            // Nothing is ever charged for a free plan, so it needs no gateway
            // plan behind it.
            if ($record->price > 0) {
                $this->syncPayMongoPlan($record);
            }
        }
    }

    /**
     * Provision the matching PayMongo billing plans (monthly and annual) and
     * persist their ids so a subscription can be created with just a
     * customer_id and plan_id.
     */
    protected function syncPayMongoPlan(Plan $plan): void
    {
        $this->provisionPlan($plan, Plan::INTERVAL_MONTHLY);
        $this->provisionPlan($plan, Plan::INTERVAL_ANNUAL);
    }

    protected function provisionPlan(Plan $plan, string $interval): void
    {
        $column = $interval === Plan::INTERVAL_ANNUAL
            ? 'paymongo_plan_id_annual'
            : 'paymongo_plan_id';

        // A gateway plan is an immutable price object, so an id that is already
        // set describes a price that may no longer be this plan's. Repricing
        // therefore has to clear these columns to take effect — see the
        // migration that renames Starter for why that is deliberate.
        if ($plan->{$column} !== null) {
            return;
        }

        if (config('paymongo.secret_key') === '') {
            return;
        }

        try {
            $paymongoPlan = (new PaymongoClient)->createPlan(
                $plan->name,
                $plan->priceForInterval($interval),
                "{$plan->name} ".($interval === Plan::INTERVAL_ANNUAL ? 'annual' : 'monthly').' subscription',
                [
                    'slug' => $plan->slug,
                    'interval' => $interval,
                    'limits' => json_encode($plan->limits ?? [], JSON_THROW_ON_ERROR),
                    'features' => json_encode($plan->features ?? [], JSON_THROW_ON_ERROR),
                    'overage_price' => $plan->overage_price,
                ],
                interval: $interval === Plan::INTERVAL_ANNUAL ? 'yearly' : 'monthly',
            );

            $plan->update([$column => $paymongoPlan['id']]);
        } catch (\Throwable $e) {
            Log::warning("Could not provision PayMongo plan for {$plan->slug} ({$interval}): {$e->getMessage()}");
        }
    }
}
