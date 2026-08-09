<?php

namespace Database\Factories;

use App\Models\BillingEvent;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingEvent>
 */
class BillingEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'event_type' => BillingEvent::EVENT_SEAT_ADDED,
            'seats_before' => 1,
            'seats_after' => 2,
            'price_per_seat' => 200000,
            'occurred_at' => now(),
        ];
    }
}
