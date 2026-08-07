<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'price' => 150000,
            'currency' => 'PHP',
            'interval' => 'monthly',
            'limits' => [
                'active_cases' => 10,
                'documents_uploaded' => 10,
                'messages_used' => 200,
            ],
            'features' => ['templates', 'exports'],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * The Starter plan configuration.
     */
    public function starter(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_STARTER,
            'name' => 'Starter',
            'price' => 150000,
            'price_annual' => 1494000,
            'overage_price' => null,
            'sort_order' => 1,
            'limits' => [
                'active_cases' => 10,
                'documents_uploaded' => 10,
                'messages_used' => 200,
            ],
            'features' => ['templates', 'exports', 'web_search'],
        ]);
    }

    /**
     * The Pro plan configuration.
     */
    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_PRO,
            'name' => 'Pro',
            'price' => 200000,
            'price_annual' => 1990000,
            'overage_price' => 350,
            'sort_order' => 2,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => 100,
                'messages_used' => 500,
            ],
            'features' => ['templates', 'exports', 'web_search', 'unlimited_cases'],
        ]);
    }

    /**
     * The Firm plan configuration.
     */
    public function firm(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Plan::SLUG_FIRM,
            'name' => 'Firm',
            'price' => 890000,
            'price_annual' => 8860000,
            'overage_price' => 300,
            'sort_order' => 3,
            'limits' => [
                'active_cases' => null,
                'documents_uploaded' => null,
                'messages_used' => 3000,
            ],
            'features' => ['templates', 'exports', 'web_search', 'unlimited_cases', 'unlimited_documents', 'priority_support'],
        ]);
    }
}
