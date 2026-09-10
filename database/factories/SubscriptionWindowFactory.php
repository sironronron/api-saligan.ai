<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionWindow>
 */
class SubscriptionWindowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth();

        return [
            'subscription_id' => Subscription::factory(),
            'window_start' => $start,
            'window_end' => $start->copy()->addMonth(),
            'budget_usd' => 5.15,
            'used_usd' => 0,
        ];
    }
}
