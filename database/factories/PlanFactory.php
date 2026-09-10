<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default plan carries every capability. Most tests want "a user who
     * can use the product" rather than a particular tier, and a default that
     * withheld features would make each of them fail for a reason that has
     * nothing to do with what they are testing. Tests that care about a
     * capability boundary name the tier they mean, or set `features` outright.
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'price' => 150000,
            'currency' => 'PHP',
            'interval' => 'monthly',
            'included_seats' => 1,
            'seat_price' => null,
            'limits' => [
                'active_cases' => 10,
                'documents_uploaded' => 10,
                'messages_used' => 200,
            ],
            'features' => PlanFeatures::capabilities(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * The free trial plan: a quarter of Standard's metered allowance, answered
     * by the base model, and inactive so it is never sold.
     */
    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_TRIAL,
            'name' => 'Free trial',
            'price' => 0,
            'price_annual' => 0,
            'overage_price' => null,
            'ai_budget_usd_cents' => 200,
            'ai_usage_multiplier' => null,
            'included_seats' => 1,
            'seat_price' => null,
            'sort_order' => 0,
            'is_active' => false,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => 13,
                'messages_used' => 63,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::WEB_SEARCH,
            ],
        ]);
    }

    /**
     * The Standard plan: volume on the base model, capped, single seat.
     */
    public function standard(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_STANDARD,
            'name' => 'Standard',
            'price' => 150000,
            'price_annual' => 1500000,
            'overage_price' => null,
            'ai_budget_usd_cents' => 515,
            'ai_usage_multiplier' => 1,
            'included_seats' => 1,
            'seat_price' => null,
            'sort_order' => 1,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => 50,
                'messages_used' => 250,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::WEB_SEARCH,
            ],
        ]);
    }

    /**
     * The Pro plan: the frontier model, deep research, scan reading.
     */
    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_PRO,
            'name' => 'Pro',
            'price' => 350000,
            'price_annual' => 3500000,
            'overage_price' => null,
            'ai_budget_usd_cents' => 2575,
            'ai_usage_multiplier' => 5,
            'included_seats' => 1,
            'seat_price' => null,
            'sort_order' => 2,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => 200,
                'messages_used' => 300,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::WEB_SEARCH,
                PlanFeatures::FRONTIER_MODEL,
                PlanFeatures::DEEP_RESEARCH,
                PlanFeatures::DOCUMENT_INTELLIGENCE,
                PlanFeatures::INTEGRATIONS,
            ],
        ]);
    }

    /**
     * The Firm plan: everything Pro has, for a team, with seats to sell.
     */
    public function firm(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_FIRM,
            'name' => 'Firm',
            'price' => 1100000,
            'price_annual' => 11000000,
            'overage_price' => null,
            'ai_budget_usd_cents' => 10300,
            'ai_usage_multiplier' => 20,
            'included_seats' => 3,
            'seat_price' => 320000,
            'sort_order' => 3,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => null,
                'messages_used' => 300,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::WEB_SEARCH,
                PlanFeatures::FRONTIER_MODEL,
                PlanFeatures::DEEP_RESEARCH,
                PlanFeatures::DOCUMENT_INTELLIGENCE,
                PlanFeatures::INTEGRATIONS,
                PlanFeatures::TEAMS,
                PlanFeatures::SUPPORT_24_7,
            ],
        ]);
    }

    /**
     * The Business plan: contract-priced, so it carries no list price, no
     * allowance, and no seat price, and is never reachable through checkout.
     */
    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_BUSINESS,
            'name' => 'Business',
            'price' => 0,
            'price_annual' => 0,
            'overage_price' => null,
            'included_seats' => 1,
            'seat_price' => null,
            'sort_order' => 4,
            'contact_sales' => true,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => null,
                'messages_used' => null,
            ],
            'features' => [
                PlanFeatures::DRAFTING,
                PlanFeatures::EXPORTS,
                PlanFeatures::WEB_SEARCH,
                PlanFeatures::FRONTIER_MODEL,
                PlanFeatures::DEEP_RESEARCH,
                PlanFeatures::DOCUMENT_INTELLIGENCE,
                PlanFeatures::INTEGRATIONS,
                PlanFeatures::TEAMS,
                PlanFeatures::GUIDED_SETUP,
                PlanFeatures::TEAM_TRAINING,
                PlanFeatures::SUPPORT_24_7,
            ],
        ]);
    }
}
