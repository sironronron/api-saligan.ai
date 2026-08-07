<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ];
    }

    /**
     * The subscription is active on the Pro plan.
     */
    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => Plan::factory()->pro(),
        ]);
    }

    /**
     * The subscription is still in its trial period.
     */
    public function onTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_ACTIVE,
            'trial_ends_at' => now()->addDays(10),
        ]);
    }
}
